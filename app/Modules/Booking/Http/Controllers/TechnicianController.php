<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Services\BookingLifecycleService;
use App\Modules\Booking\Services\TariffPricingService;
use App\Modules\Identity\Policies\BookingPolicy;
use App\Modules\Identity\Services\LocationAccessWindow;
use App\Modules\Payment\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TechnicianController extends Controller
{
    private const REQUIRED_DOCUMENTS = ['national_id', 'guild_license'];

    public function register(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->status !== 'active') {
            return $this->error('FORBIDDEN_TECHNICIAN_REGISTRATION', 'برای ثبت‌نام متخصص، حساب فعال لازم است.', 403);
        }

        $result = DB::transaction(function () use ($user): array {
            $roleId = DB::table('roles')->where('name', 'technician')->value('id');
            if (! $roleId) {
                return ['error' => 'TECHNICIAN_ROLE_UNAVAILABLE'];
            }
            $existingProfile = DB::table('technician_profiles')->where('user_id', $user->id)->first();
            DB::table('role_user')->insertOrIgnore([
                'user_id' => $user->id,
                'role_id' => $roleId,
            ]);
            DB::table('technician_profiles')->insertOrIgnore([
                'user_id' => $user->id,
                'status' => 'pending_verification',
                'is_online' => false,
                'is_available' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'profile' => DB::table('technician_profiles')->where('user_id', $user->id)->first(),
                'created' => $existingProfile === null,
            ];
        });
        if (isset($result['error'])) {
            return $this->error($result['error'], 'نقش متخصص در سامانه پیکربندی نشده است.', 503);
        }

        return response()->json([
            'data' => $this->profileData($result['profile']),
            'message' => 'پروفایل متخصص ایجاد شد و تا تأیید مدارک امکان دریافت مأموریت ندارد.',
        ], $result['created'] ? 201 : 200);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $profile = $this->technicianProfile($request, false);
        if (! $profile) {
            return $this->error('TECHNICIAN_PROFILE_REQUIRED', 'برای مشاهدهٔ پنل متخصص ابتدا ثبت‌نام کنید.', 403);
        }

        return response()->json([
            'data' => [
                'profile' => $this->profileData($profile),
                'services' => DB::table('services')
                    ->where('is_active', true)
                    ->orderBy('title_fa')
                    ->get(['id', 'title_fa']),
                'service_areas' => DB::table('service_areas')
                    ->where('is_active', true)
                    ->orderBy('city_name')
                    ->orderBy('zone_name')
                    ->get(['id', 'city_name', 'zone_name']),
            ],
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $profile = $this->technicianProfile($request, false);
        if (! $profile) {
            return $this->error('TECHNICIAN_PROFILE_REQUIRED', 'ابتدا ثبت‌نام متخصص را انجام دهید.', 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'license_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('technician_profiles', 'license_number')->ignore($profile->id),
            ],
            'guild_registration_no' => ['nullable', 'string', 'max:50'],
            'vehicle_type' => ['nullable', Rule::in(['motor', 'car', 'van', 'other'])],
            'vehicle_plate' => ['nullable', 'string', 'max:30'],
            'service_ids' => ['sometimes', 'array', 'max:100'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'service_area_ids' => ['sometimes', 'array', 'max:100'],
            'service_area_ids.*' => ['integer', 'distinct', 'exists:service_areas,id'],
            'working_hours' => ['sometimes', 'array', 'max:42'],
            'working_hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'working_hours.*.starts_at' => ['required', 'date_format:H:i'],
            'working_hours.*.ends_at' => ['required', 'date_format:H:i'],
            'working_hours.*.capacity' => ['sometimes', 'integer', 'between:1,10'],
            'working_hours.*.is_active' => ['sometimes', 'boolean'],
        ]);
        $hoursByDay = [];
        foreach ($validated['working_hours'] ?? [] as $slot) {
            if ($slot['ends_at'] <= $slot['starts_at']) {
                return $this->error('INVALID_WORKING_HOURS', 'پایان هر بازهٔ کاری باید بعد از شروع آن باشد.', 422);
            }
            $hoursByDay[$slot['day_of_week']][] = $slot;
        }
        foreach ($hoursByDay as $daySlots) {
            usort($daySlots, static fn (array $first, array $second): int => strcmp($first['starts_at'], $second['starts_at'])
            );
            for ($index = 1; $index < count($daySlots); $index++) {
                if ($daySlots[$index]['starts_at'] < $daySlots[$index - 1]['ends_at']) {
                    return $this->error('OVERLAPPING_WORKING_HOURS', 'بازه‌های کاری یک روز نباید هم‌پوشانی داشته باشند.', 422);
                }
            }
        }

        if (array_key_exists('service_ids', $validated)) {
            $activeServiceCount = DB::table('services')
                ->whereIn('id', $validated['service_ids'])
                ->where('is_active', true)
                ->count();
            if ($activeServiceCount !== count($validated['service_ids'])) {
                return $this->error('INACTIVE_SERVICE_SELECTED', 'فهرست خدمات شامل خدمت غیرفعال است.', 422);
            }
        }
        if (array_key_exists('service_area_ids', $validated)) {
            $activeAreaCount = DB::table('service_areas')
                ->whereIn('id', $validated['service_area_ids'])
                ->where('is_active', true)
                ->count();
            if ($activeAreaCount !== count($validated['service_area_ids'])) {
                return $this->error('INACTIVE_SERVICE_AREA_SELECTED', 'فهرست مناطق شامل محدودهٔ غیرفعال است.', 422);
            }
        }

        DB::transaction(function () use ($request, $profile, $validated): void {
            DB::table('technician_profiles')->where('id', $profile->id)->lockForUpdate()->first();
            if (isset($validated['name'])) {
                DB::table('users')->where('id', $request->user()->id)->update([
                    'name' => $validated['name'],
                    'updated_at' => now(),
                ]);
            }
            $profileFields = array_intersect_key($validated, array_flip([
                'license_number',
                'guild_registration_no',
                'vehicle_type',
                'vehicle_plate',
            ]));
            if ($profileFields) {
                DB::table('technician_profiles')->where('id', $profile->id)->update(array_merge(
                    $profileFields,
                    ['updated_at' => now()]
                ));
            }
            if (array_key_exists('service_ids', $validated)) {
                DB::table('technician_services')->where('technician_id', $profile->id)->delete();
                foreach ($validated['service_ids'] as $serviceId) {
                    DB::table('technician_services')->insert([
                        'technician_id' => $profile->id,
                        'service_id' => $serviceId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            if (array_key_exists('service_area_ids', $validated)) {
                DB::table('technician_service_areas')->where('technician_id', $profile->id)->delete();
                foreach ($validated['service_area_ids'] as $areaId) {
                    DB::table('technician_service_areas')->insert([
                        'technician_id' => $profile->id,
                        'service_area_id' => $areaId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            if (array_key_exists('working_hours', $validated)) {
                DB::table('technician_working_hours')->where('technician_id', $profile->id)->delete();
                foreach ($validated['working_hours'] as $slot) {
                    DB::table('technician_working_hours')->insert([
                        'technician_id' => $profile->id,
                        'day_of_week' => $slot['day_of_week'],
                        'starts_at' => $slot['starts_at'],
                        'ends_at' => $slot['ends_at'],
                        'capacity' => $slot['capacity'] ?? 1,
                        'is_active' => $slot['is_active'] ?? true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return response()->json([
            'data' => $this->profileData(DB::table('technician_profiles')->where('id', $profile->id)->first()),
        ]);
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $profile = $this->technicianProfile($request, false);
        if (! $profile) {
            return $this->error('TECHNICIAN_PROFILE_REQUIRED', 'ابتدا ثبت‌نام متخصص را انجام دهید.', 403);
        }
        $validated = $request->validate([
            'document_type' => ['required', Rule::in(['national_id', 'guild_license', 'criminal_record', 'vehicle_card'])],
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
        $file = $validated['document'];
        $path = $file->store('technician-documents/'.$profile->id, 'local');
        if (! $path) {
            return $this->error('DOCUMENT_STORAGE_FAILED', 'ذخیرهٔ امن مدرک انجام نشد؛ دوباره تلاش کنید.', 500);
        }

        $now = now();
        try {
            $documentId = DB::transaction(function () use ($file, $path, $profile, $validated, $now): int {
                $documentId = DB::table('technician_documents')->insertGetId([
                    'technician_id' => $profile->id,
                    'document_type' => $validated['document_type'],
                    'file_path' => $path,
                    'status' => 'pending',
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $file->getMimeType(),
                    'file_size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('technician_profiles')->where('id', $profile->id)->update([
                    'status' => 'pending_verification',
                    'is_online' => false,
                    'is_available' => false,
                    'current_lat' => null,
                    'current_lng' => null,
                    'last_telemetry_at' => null,
                    'updated_at' => $now,
                ]);

                return $documentId;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'data' => [
                'id' => $documentId,
                'document_type' => $validated['document_type'],
                'status' => 'pending',
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'file_size_bytes' => $file->getSize(),
            ],
        ], 201);
    }

    public function pendingReviews(Request $request): JsonResponse
    {
        if (! $this->hasPermission((int) $request->user()->id, 'technicians.verify_kyc', ['super_admin'])) {
            return $this->error('FORBIDDEN_TECHNICIAN_REVIEW', 'بررسی مدارک فقط برای مدیر مجاز است.', 403);
        }

        $documents = DB::table('technician_documents')
            ->join('technician_profiles', 'technician_documents.technician_id', '=', 'technician_profiles.id')
            ->join('users', 'technician_profiles.user_id', '=', 'users.id')
            ->where('technician_documents.status', 'pending')
            ->orderBy('technician_documents.created_at')
            ->limit(100)
            ->get([
                'technician_documents.id',
                'technician_documents.technician_id',
                'technician_documents.document_type',
                'technician_documents.original_name',
                'technician_documents.mime_type',
                'technician_documents.file_size_bytes',
                'technician_documents.sha256',
                'technician_profiles.status as technician_status',
                'users.name as technician_name',
                'users.phone_number',
            ]);

        return response()->json(['data' => $documents]);
    }

    public function documentFile(Request $request, int $technicianId, int $documentId)
    {
        if (! $this->hasPermission((int) $request->user()->id, 'technicians.verify_kyc', ['super_admin'])) {
            return $this->error('FORBIDDEN_TECHNICIAN_REVIEW', 'مشاهدهٔ مدارک فقط برای مدیر مجاز است.', 403);
        }
        $document = DB::table('technician_documents')
            ->where('id', $documentId)
            ->where('technician_id', $technicianId)
            ->first();
        if (! $document || ! Storage::disk('local')->exists($document->file_path)) {
            return $this->error('TECHNICIAN_DOCUMENT_NOT_FOUND', 'مدرک در storage خصوصی پیدا نشد.', 404);
        }
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => 'technician.document.viewed',
            'auditable_type' => 'technician_document',
            'auditable_id' => $documentId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'new_values' => json_encode(['technician_id' => $technicianId], JSON_THROW_ON_ERROR),
            'reason' => 'مشاهده مدرک خصوصی برای بررسی صلاحیت',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->file(Storage::disk('local')->path($document->file_path), [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function reviewDocument(Request $request, int $technicianId, int $documentId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->hasPermission((int) $user->id, 'technicians.verify_kyc', ['super_admin'])) {
            return $this->error('FORBIDDEN_TECHNICIAN_REVIEW', 'بررسی مدارک فقط برای مدیر مجاز است.', 403);
        }
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'rejection_reason' => ['required_if:decision,rejected', 'nullable', 'string', 'min:3', 'max:1000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($user, $technicianId, $documentId, $validated, $request): array {
            $profile = DB::table('technician_profiles')->where('id', $technicianId)->lockForUpdate()->first();
            $document = DB::table('technician_documents')
                ->where('id', $documentId)
                ->where('technician_id', $technicianId)
                ->lockForUpdate()
                ->first();
            if (! $profile || ! $document) {
                return ['error' => 'TECHNICIAN_DOCUMENT_NOT_FOUND'];
            }
            if ($document->status !== 'pending') {
                return ['error' => 'DOCUMENT_ALREADY_REVIEWED'];
            }

            DB::table('technician_documents')->where('id', $documentId)->update([
                'status' => $validated['decision'],
                'rejection_reason' => $validated['decision'] === 'rejected'
                    ? $validated['rejection_reason']
                    : null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            $latestRequired = DB::table('technician_documents')
                ->where('technician_id', $technicianId)
                ->whereIn('document_type', self::REQUIRED_DOCUMENTS)
                ->whereIn('id', function ($query) use ($technicianId) {
                    $query->selectRaw('MAX(id)')
                        ->from('technician_documents')
                        ->where('technician_id', $technicianId)
                        ->whereIn('document_type', self::REQUIRED_DOCUMENTS)
                        ->groupBy('document_type');
                })
                ->where('status', 'approved')
                ->pluck('document_type')
                ->all();
            $verified = count(array_intersect(self::REQUIRED_DOCUMENTS, $latestRequired)) === count(self::REQUIRED_DOCUMENTS);
            $status = $verified
                ? 'verified'
                : ($validated['decision'] === 'rejected' ? 'rejected' : 'pending_verification');
            DB::table('technician_profiles')->where('id', $technicianId)->update([
                'status' => $status,
                'is_online' => false,
                'is_available' => false,
                'current_lat' => null,
                'current_lng' => null,
                'last_telemetry_at' => null,
                'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $user->id,
                'action' => 'technician.document.reviewed',
                'auditable_type' => 'technician_document',
                'auditable_id' => $documentId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'old_values' => json_encode([
                    'status' => $document->status,
                    'technician_status' => $profile->status,
                ], JSON_THROW_ON_ERROR),
                'new_values' => json_encode([
                    'status' => $validated['decision'],
                    'technician_status' => $status,
                ], JSON_THROW_ON_ERROR),
                'reason' => $validated['reason'] ?? $validated['rejection_reason'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => $status];
        });
        if (isset($result['error'])) {
            if ($result['error'] === 'DOCUMENT_ALREADY_REVIEWED') {
                return $this->error($result['error'], 'وضعیت این مدرک قبلاً ثبت شده است.', 409);
            }

            return $this->error($result['error'], 'مدرک در صف بررسی پیدا نشد.', 404);
        }

        return response()->json([
            'data' => ['technician_status' => $result['status']],
            'message' => $result['status'] === 'verified'
                ? 'مدارک لازم تأیید شد؛ متخصص می‌تواند تکمیل پروفایل کند.'
                : 'تصمیم مدرک ثبت شد؛ برای تأیید متخصص همهٔ مدارک الزامی باید تأیید شوند.',
        ]);
    }

    public function availability(Request $request): JsonResponse
    {
        $profile = $this->technicianProfile($request, false);
        if (! $profile) {
            return $this->error('TECHNICIAN_PROFILE_REQUIRED', 'پروفایل متخصص پیدا نشد.', 403);
        }
        $validated = $request->validate([
            'online' => ['required', 'boolean'],
            'latitude' => ['required_if:online,true', 'nullable', 'numeric', 'between:-90,90', 'decimal:0,7'],
            'longitude' => ['required_if:online,true', 'nullable', 'numeric', 'between:-180,180', 'decimal:0,7'],
        ]);
        if (! $validated['online']) {
            DB::table('technician_profiles')->where('id', $profile->id)->update([
                'is_online' => false,
                'is_available' => false,
                'current_lat' => null,
                'current_lng' => null,
                'last_telemetry_at' => null,
                'updated_at' => now(),
            ]);

            return response()->json(['data' => ['online' => false, 'available' => false]]);
        }
        if ($profile->status !== 'verified') {
            return $this->error('TECHNICIAN_NOT_VERIFIED', 'تا تأیید مدارک، آنلاین‌شدن و دریافت مأموریت ممکن نیست.', 403);
        }

        $serviceCount = DB::table('technician_services')
            ->join('services', 'technician_services.service_id', '=', 'services.id')
            ->where('technician_services.technician_id', $profile->id)
            ->where('services.is_active', true)
            ->count();
        $areaCount = DB::table('technician_service_areas')
            ->join('service_areas', 'technician_service_areas.service_area_id', '=', 'service_areas.id')
            ->where('technician_service_areas.technician_id', $profile->id)
            ->where('service_areas.is_active', true)
            ->count();
        if (! $serviceCount || ! $areaCount) {
            return $this->error('TECHNICIAN_PROFILE_INCOMPLETE', 'برای آنلاین‌شدن دست‌کم یک خدمت و محدودهٔ فعال تعیین کنید.', 422);
        }

        $now = now();
        $day = $now->dayOfWeek;
        $time = $now->format('H:i:s');
        $hours = DB::table('technician_working_hours')
            ->where('technician_id', $profile->id)
            ->where('day_of_week', $day)
            ->where('is_active', true)
            ->where('starts_at', '<=', $time)
            ->where('ends_at', '>', $time)
            ->orderByDesc('capacity')
            ->first();
        if (! $hours) {
            return $this->error('OUTSIDE_WORKING_HOURS', 'در حال حاضر خارج از ساعت کاری ثبت‌شده هستید.', 409);
        }
        $activeAssignments = DB::table('assignments')
            ->join('bookings', 'assignments.booking_id', '=', 'bookings.id')
            ->where('assignments.technician_id', $profile->id)
            ->where('assignments.status', 'active')
            ->whereIn('bookings.status', ['assigned', 'en_route', 'arrived', 'in_progress', 'payment_pending'])
            ->count();
        if ($activeAssignments >= $hours->capacity) {
            return $this->error('TECHNICIAN_CAPACITY_REACHED', 'ظرفیت فعال مأموریت شما تکمیل است.', 409);
        }

        DB::table('technician_profiles')->where('id', $profile->id)->update([
            'is_online' => true,
            'is_available' => true,
            'current_lat' => $validated['latitude'],
            'current_lng' => $validated['longitude'],
            'last_telemetry_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(['data' => ['online' => true, 'available' => true, 'capacity' => $hours->capacity]]);
    }

    public function createOffers(Request $request, int $bookingId, BookingLifecycleService $lifecycle): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->hasPermission((int) $user->id, 'technicians.manage_dispatch', ['super_admin', 'dispatcher'])) {
            return $this->error('FORBIDDEN_DISPATCH', 'ثبت پیشنهاد اعزام فقط برای مسئول اعزام مجاز است.', 403);
        }
        $validated = $request->validate([
            'technician_ids' => ['required', 'array', 'min:1', 'max:50'],
            'technician_ids.*' => ['required', 'integer', 'distinct', 'exists:technician_profiles,id'],
            'response_window_seconds' => ['sometimes', 'integer', 'between:30,600'],
        ]);

        try {
            $result = DB::transaction(function () use ($user, $bookingId, $validated, $lifecycle): array {
                $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
                if (! $booking) {
                    return ['error' => 'BOOKING_NOT_FOUND'];
                }
                if (! in_array($booking->status, ['draft', 'broadcasting'], true)) {
                    return ['error' => 'BOOKING_NOT_DISPATCHABLE'];
                }
                $estimate = $booking->price_estimate_id
                    ? DB::table('price_estimates')->where('id', $booking->price_estimate_id)->first()
                    : null;
                $areaId = $estimate?->service_area_id;
                if (! $areaId) {
                    return ['error' => 'SERVICE_AREA_UNAVAILABLE'];
                }
                $eligible = DB::table('technician_profiles')
                    ->join('users', 'technician_profiles.user_id', '=', 'users.id')
                    ->join('role_user', 'users.id', '=', 'role_user.user_id')
                    ->join('roles', 'role_user.role_id', '=', 'roles.id')
                    ->join('technician_services', 'technician_profiles.id', '=', 'technician_services.technician_id')
                    ->join('services', function ($join) {
                        $join->on('services.id', '=', 'technician_services.service_id')
                            ->where('services.is_active', true);
                    })
                    ->join('technician_service_areas', 'technician_profiles.id', '=', 'technician_service_areas.technician_id')
                    ->join('service_areas', function ($join) {
                        $join->on('service_areas.id', '=', 'technician_service_areas.service_area_id')
                            ->where('service_areas.is_active', true);
                    })
                    ->whereIn('technician_profiles.id', $validated['technician_ids'])
                    ->where('technician_profiles.status', 'verified')
                    ->where('technician_profiles.is_online', true)
                    ->where('technician_profiles.is_available', true)
                    ->where('users.status', 'active')
                    ->where('roles.name', 'technician')
                    ->where('technician_services.service_id', $booking->service_id)
                    ->where('technician_service_areas.service_area_id', $areaId)
                    ->whereNotNull('technician_profiles.current_lat')
                    ->whereNotNull('technician_profiles.current_lng')
                    ->select('technician_profiles.*')
                    ->distinct()
                    ->get();
                if ($eligible->count() !== count($validated['technician_ids'])) {
                    return ['error' => 'TECHNICIAN_NOT_ELIGIBLE'];
                }
                $window = $validated['response_window_seconds'] ?? 90;
                $created = [];
                foreach ($eligible as $technician) {
                    $capacity = $this->capacityForBooking((int) $technician->id, $booking);
                    if ($capacity === null || $this->activeAssignmentCount((int) $technician->id) >= $capacity) {
                        return ['error' => 'TECHNICIAN_CAPACITY_REACHED'];
                    }
                    $round = (int) DB::table('dispatch_offers')
                        ->where('booking_id', $bookingId)
                        ->where('technician_id', $technician->id)
                        ->max('round_number') + 1;
                    $distance = TariffPricingService::distanceKm(
                        (float) $technician->current_lat,
                        (float) $technician->current_lng,
                        (float) $booking->latitude,
                        (float) $booking->longitude
                    );
                    $created[] = DB::table('dispatch_offers')->insertGetId([
                        'booking_id' => $bookingId,
                        'technician_id' => $technician->id,
                        'round_number' => $round,
                        'distance_km' => round($distance, 2),
                        'offered_earnings' => max(
                            0,
                            round((float) $booking->estimated_price * (1 - (float) $booking->platform_commission_rate / 100), 2)
                        ),
                        'status' => 'pending',
                        'expires_at' => now()->addSeconds($window),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                if ($booking->status === 'draft') {
                    $transition = $lifecycle->transition(
                        (int) $booking->id,
                        'broadcasting',
                        (int) $user->id,
                        'پیشنهاد مأموریت برای متخصصان واجد شرایط ارسال شد.'
                    );
                    if (isset($transition['error'])) {
                        return ['error' => $transition['error']];
                    }
                }

                return ['offer_ids' => $created];
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->error('DUPLICATE_DISPATCH_OFFER', 'پیشنهاد تکراری برای این مأموریت ایجاد نشد.', 409);
            }
            throw $exception;
        }
        if (isset($result['error'])) {
            return $this->dispatchError($result['error']);
        }

        return response()->json(['data' => ['offer_ids' => $result['offer_ids']]], 201);
    }

    public function offers(Request $request): JsonResponse
    {
        $profile = $this->technicianProfile($request);
        if (! $profile) {
            return $this->error('TECHNICIAN_NOT_VERIFIED', 'فقط متخصص تأییدشده می‌تواند پیشنهاد مأموریت دریافت کند.', 403);
        }
        $offers = DB::table('dispatch_offers')
            ->join('bookings', 'dispatch_offers.booking_id', '=', 'bookings.id')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->leftJoin('price_estimates', 'bookings.price_estimate_id', '=', 'price_estimates.id')
            ->leftJoin('service_areas', 'price_estimates.service_area_id', '=', 'service_areas.id')
            ->where('dispatch_offers.technician_id', $profile->id)
            ->where('dispatch_offers.status', 'pending')
            ->where('dispatch_offers.expires_at', '>', now())
            ->where('bookings.status', 'broadcasting')
            ->orderBy('dispatch_offers.expires_at')
            ->get([
                'dispatch_offers.id',
                'dispatch_offers.distance_km',
                'dispatch_offers.offered_earnings',
                'dispatch_offers.expires_at',
                'bookings.booking_code',
                'bookings.urgency',
                'bookings.scheduled_at',
                'services.title_fa as service_name',
                'service_areas.city_name',
                'service_areas.zone_name',
            ]);
        $offers->transform(static function (object $offer): object {
            $offer->distance_method = DB::getDriverName() === 'pgsql'
                ? 'postgis_geography'
                : 'haversine_geodesic';
            $offer->estimated_travel_time_minutes = null;
            $offer->travel_time_status = 'unavailable';

            return $offer;
        });

        return response()->json(['data' => $offers]);
    }

    public function respondOffer(
        Request $request,
        int $offerId,
        BookingLifecycleService $lifecycle,
        LocationAccessWindow $locationAccessWindow,
    ): JsonResponse
    {
        $profile = $this->technicianProfile($request);
        if (! $profile) {
            return $this->error('TECHNICIAN_NOT_VERIFIED', 'فقط متخصص تأییدشده می‌تواند به مأموریت پاسخ دهد.', 403);
        }
        $validated = $request->validate(['decision' => ['required', Rule::in(['accepted', 'rejected'])]]);
        $userId = (int) $request->user()->id;

        try {
            $result = DB::transaction(function () use (
                $profile,
                $offerId,
                $validated,
                $userId,
                $lifecycle,
                $locationAccessWindow,
            ): array {
                $offer = DB::table('dispatch_offers')->where('id', $offerId)->lockForUpdate()->first();
                if (! $offer || (int) $offer->technician_id !== (int) $profile->id) {
                    return ['error' => 'OFFER_NOT_FOUND'];
                }
                if ($offer->status !== 'pending') {
                    return ['error' => 'OFFER_ALREADY_RESPONDED'];
                }
                if (now()->greaterThanOrEqualTo($offer->expires_at)) {
                    DB::table('dispatch_offers')->where('id', $offerId)->update([
                        'status' => 'expired',
                        'updated_at' => now(),
                    ]);

                    return ['error' => 'OFFER_EXPIRED'];
                }
                if ($validated['decision'] === 'rejected') {
                    DB::table('dispatch_offers')->where('id', $offerId)->update([
                        'status' => 'rejected',
                        'updated_at' => now(),
                    ]);

                    return ['status' => 'rejected'];
                }

                $booking = DB::table('bookings')->where('id', $offer->booking_id)->lockForUpdate()->first();
                if (! $booking || $booking->status !== 'broadcasting' || $booking->technician_id !== null) {
                    return ['error' => 'BOOKING_ALREADY_ASSIGNED'];
                }
                $currentProfile = DB::table('technician_profiles')
                    ->where('id', $profile->id)
                    ->lockForUpdate()
                    ->first();
                $hasTechnicianRole = DB::table('role_user')
                    ->join('roles', 'role_user.role_id', '=', 'roles.id')
                    ->join('users', 'role_user.user_id', '=', 'users.id')
                    ->where('role_user.user_id', $userId)
                    ->where('users.status', 'active')
                    ->where('roles.name', 'technician')
                    ->exists();
                if (! $currentProfile || $currentProfile->status !== 'verified') {
                    return ['error' => 'TECHNICIAN_NOT_VERIFIED'];
                }
                if (! $hasTechnicianRole || ! $currentProfile->is_online || ! $currentProfile->is_available) {
                    return ['error' => 'TECHNICIAN_NOT_AVAILABLE'];
                }
                if (! $this->eligibleForBooking($currentProfile, $booking)) {
                    return ['error' => 'TECHNICIAN_NOT_ELIGIBLE'];
                }
                $capacity = $this->capacityForBooking((int) $currentProfile->id, $booking);
                if ($capacity === null || $this->activeAssignmentCount((int) $currentProfile->id) >= $capacity) {
                    return ['error' => 'TECHNICIAN_CAPACITY_REACHED'];
                }
                $now = now();
                DB::table('assignments')->insert([
                    'booking_id' => $booking->id,
                    'technician_id' => $currentProfile->id,
                    'offer_id' => $offerId,
                    'status' => 'active',
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('bookings')->where('id', $booking->id)->update([
                    'technician_id' => $currentProfile->id,
                    'assigned_at' => $now,
                    'location_access_expires_at' => $now->copy()->addHours($locationAccessWindow->hours()),
                    'updated_at' => $now,
                ]);
                DB::table('dispatch_offers')->where('booking_id', $booking->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'expired', 'updated_at' => $now]);
                DB::table('dispatch_offers')->where('id', $offerId)->update([
                    'status' => 'accepted',
                    'updated_at' => $now,
                ]);
                DB::table('technician_profiles')->where('id', $currentProfile->id)->update([
                    'is_available' => false,
                    'updated_at' => $now,
                ]);
                $transition = $lifecycle->transition(
                    (int) $booking->id,
                    'assigned',
                    $userId,
                    'متخصص پیشنهاد مأموریت را پذیرفت.'
                );
                if (isset($transition['error'])) {
                    return ['error' => $transition['error']];
                }

                return ['status' => 'accepted', 'booking_code' => $booking->booking_code];
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->error('BOOKING_ALREADY_ASSIGNED', 'این مأموریت هم‌زمان به متخصص دیگری تخصیص یافت.', 409);
            }
            throw $exception;
        }
        if (isset($result['error'])) {
            return $this->offerError($result['error']);
        }

        return response()->json(['data' => $result]);
    }

    public function missions(Request $request): JsonResponse
    {
        $profile = $this->technicianProfile($request);
        if (! $profile) {
            return $this->error('TECHNICIAN_NOT_VERIFIED', 'فقط متخصص تأییدشده می‌تواند مأموریت‌های خود را مشاهده کند.', 403);
        }
        $missions = DB::table('assignments')
            ->join('bookings', 'assignments.booking_id', '=', 'bookings.id')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->where('assignments.technician_id', $profile->id)
            ->whereIn('assignments.status', ['active', 'fulfilled'])
            ->orderByDesc('assignments.assigned_at')
            ->limit(100)
            ->get([
                'bookings.booking_code',
                'bookings.status',
                'bookings.scheduled_at',
                'bookings.location_access_expires_at',
                'services.title_fa as service_name',
                'assignments.status as assignment_status',
                'assignments.assigned_at',
            ]);

        return response()->json(['data' => $missions]);
    }

    public function mission(Request $request, string $bookingCode, BookingPolicy $policy): JsonResponse
    {
        $profile = $this->technicianProfile($request);
        if (! $profile) {
            return $this->error('TECHNICIAN_NOT_VERIFIED', 'فقط متخصص تأییدشده می‌تواند مأموریت مشاهده کند.', 403);
        }
        $booking = DB::table('bookings')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->where('bookings.booking_code', $bookingCode)
            ->first(['bookings.*', 'services.title_fa as service_name', 'services.requires_ownership_proof', 'services.requires_completion_evidence']);
        if (! $booking || (int) $booking->technician_id !== (int) $profile->id ||
            ! DB::table('assignments')->where('booking_id', $booking->id)
                ->where('technician_id', $profile->id)->where('status', 'active')->exists()) {
            return $this->error('MISSION_NOT_FOUND', 'مأموریت فعال متعلق به شما پیدا نشد.', 404);
        }
        if (! $policy->viewCustomerLocation((int) $request->user()->id, $booking)) {
            return $this->error('LOCATION_ACCESS_EXPIRED', 'مهلت مجاز مشاهدهٔ نشانی این مأموریت پایان یافته است.', 403);
        }

        $evidence = DB::table('booking_evidence')->where('booking_id', $booking->id)->count();

        return response()->json([
            'data' => [
                'id' => (int) $booking->id,
                'booking_code' => $booking->booking_code,
                'service_name' => $booking->service_name,
                'status' => $booking->status,
                'urgency' => $booking->urgency,
                'scheduled_at' => $booking->scheduled_at,
                'street_address' => $booking->street_address,
                'latitude' => (float) $booking->latitude,
                'longitude' => (float) $booking->longitude,
                'problem_description' => $booking->problem_description,
                'estimated_price' => (float) $booking->estimated_price,
                'location_access_expires_at' => $booking->location_access_expires_at,
                'requires_ownership_check' => (bool) $booking->requires_ownership_proof,
                'ownership_status' => DB::table('booking_authorizations')->where('booking_id', $booking->id)->value('status'),
                'requires_completion_evidence' => (bool) $booking->requires_completion_evidence,
                'evidence_count' => $evidence,
            ],
        ]);
    }

    public function updateMissionStatus(
        Request $request,
        string $bookingCode,
        BookingLifecycleService $lifecycle,
        BookingPolicy $policy,
    ): JsonResponse {
        $profile = $this->technicianProfile($request);
        if (! $profile) {
            return $this->error('TECHNICIAN_NOT_VERIFIED', 'فقط متخصص تأییدشده می‌تواند وضعیت مأموریت را ثبت کند.', 403);
        }
        $validated = $request->validate([
            'status' => ['required', Rule::in(['en_route', 'arrived', 'in_progress', 'payment_pending'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $booking = DB::table('bookings')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->where('bookings.booking_code', $bookingCode)
            ->first(['bookings.*', 'services.requires_ownership_proof', 'services.requires_completion_evidence']);
        if (! $booking || (int) $booking->technician_id !== (int) $profile->id ||
            ! DB::table('assignments')->where('booking_id', $booking->id)
                ->where('technician_id', $profile->id)->where('status', 'active')->exists()) {
            return $this->error('MISSION_NOT_FOUND', 'مأموریت فعال متعلق به شما پیدا نشد.', 404);
        }
        if (! $policy->viewCustomerLocation((int) $request->user()->id, $booking)) {
            return $this->error('LOCATION_ACCESS_EXPIRED', 'مهلت مأموریت پایان یافته؛ تغییر وضعیت ممکن نیست.', 403);
        }
        $target = $validated['status'];
        if ($target === 'in_progress' && $booking->requires_ownership_proof) {
            $authorityStatus = DB::table('booking_authorizations')->where('booking_id', $booking->id)->value('status');
            if ($authorityStatus !== 'technician_verified') {
                return $this->error('CUSTOMER_AUTHORITY_NOT_VERIFIED', 'پیش از شروع کار باید اختیار دسترسی مشتری تأیید شود.', 409);
            }
        }
        if ($target === 'payment_pending' && $booking->requires_completion_evidence &&
            ! DB::table('booking_evidence')->where('booking_id', $booking->id)->exists()) {
            return $this->error('COMPLETION_EVIDENCE_REQUIRED', 'برای پایان این خدمت، مدرک انجام کار بارگذاری کنید.', 422);
        }

        $attributes = match ($target) {
            'arrived' => ['arrived_at' => now()],
            'in_progress' => ['started_at' => now()],
            'payment_pending' => ['completed_at' => now()],
            default => [],
        };
        if ($target === 'payment_pending') {
            $total = (float) $booking->estimated_price + (float) DB::table('scope_change_requests')
                ->join('customer_approvals', 'scope_change_requests.id', '=', 'customer_approvals.scope_change_id')
                ->where('scope_change_requests.booking_id', $booking->id)
                ->where('scope_change_requests.status', 'approved')
                ->where('customer_approvals.approved', true)
                ->sum('scope_change_requests.additional_amount');
            $commission = round($total * (float) $booking->platform_commission_rate / 100, 2);
            $attributes = array_merge($attributes, [
                'final_price' => $total,
                'platform_commission_amount' => $commission,
                'technician_earnings' => $total - $commission,
            ]);
        }
        $result = DB::transaction(function () use (
            $lifecycle,
            $booking,
            $target,
            $request,
            $validated,
            $attributes,
            $profile
        ): array {
            $transition = $lifecycle->transition(
                (int) $booking->id,
                $target,
                (int) $request->user()->id,
                $validated['comment'] ?? null,
                $attributes
            );
            if (isset($transition['error'])) {
                return $transition;
            }
            if ($target === 'payment_pending') {
                DB::table('assignments')->where('booking_id', $booking->id)
                    ->where('technician_id', $profile->id)->update([
                        'status' => 'fulfilled',
                        'released_at' => now(),
                        'updated_at' => now(),
                    ]);
                DB::table('technician_profiles')->where('id', $profile->id)->update([
                    'is_online' => false,
                    'is_available' => false,
                    'current_lat' => null,
                    'current_lng' => null,
                    'last_telemetry_at' => null,
                    'updated_at' => now(),
                ]);
            }

            return $transition;
        }, 3);
        if (isset($result['error'])) {
            return $this->error($result['error'], 'انتقال وضعیت مأموریت با وضعیت فعلی سازگار نیست.', 409);
        }

        return response()->json(['data' => ['status' => $result['booking']->status]]);
    }

    public function uploadEvidence(
        Request $request,
        string $bookingCode,
        BookingPolicy $policy,
    ): JsonResponse
    {
        $profile = $this->technicianProfile($request);
        if (! $profile) {
            return $this->error('TECHNICIAN_NOT_VERIFIED', 'فقط متخصص تأییدشده می‌تواند مدرک مأموریت بارگذاری کند.', 403);
        }
        $validated = $request->validate([
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
        $booking = DB::table('bookings')->where('booking_code', $bookingCode)->first();
        if (! $booking || (int) $booking->technician_id !== (int) $profile->id ||
            ! DB::table('assignments')->where('booking_id', $booking->id)
                ->where('technician_id', $profile->id)->where('status', 'active')->exists()) {
            return $this->error('MISSION_NOT_FOUND', 'مأموریت فعال متعلق به شما پیدا نشد.', 404);
        }
        if (! $policy->viewCustomerLocation((int) $request->user()->id, $booking)) {
            return $this->error('LOCATION_ACCESS_EXPIRED', 'مهلت مجاز مأموریت پایان یافته است.', 403);
        }
        if (! in_array($booking->status, ['arrived', 'in_progress'], true)) {
            return $this->error('EVIDENCE_UPLOAD_NOT_ALLOWED', 'مدرک فقط پس از حضور متخصص و پیش از پایان کار دریافت می‌شود.', 409);
        }

        $file = $validated['evidence'];
        $path = $file->store('booking-evidence/'.$booking->id, 'local');
        if (! $path) {
            return $this->error('EVIDENCE_STORAGE_FAILED', 'ذخیرهٔ امن مدرک انجام نشد؛ دوباره تلاش کنید.', 500);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        try {
            $evidenceId = DB::table('booking_evidence')->insertGetId([
                'booking_id' => $booking->id,
                'technician_id' => $profile->id,
                'file_path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $file->getMimeType(),
                'file_size_bytes' => $file->getSize(),
                'sha256' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => ['id' => $evidenceId, 'sha256' => $hash]], 201);
    }

    public function earnings(Request $request): JsonResponse
    {
        $profile = $this->technicianProfile($request, false);
        if (! $profile) {
            return $this->error('TECHNICIAN_PROFILE_REQUIRED', 'پروفایل متخصص پیدا نشد.', 403);
        }
        $bookingIds = DB::table('bookings')
            ->where('technician_id', $profile->id)
            ->whereIn('status', ['payment_pending', 'completed'])
            ->whereNotIn('id', DB::table('payments')->where('status', 'refunded')->select('booking_id'))
            ->pluck('id');
        $bookings = DB::table('bookings')->whereIn('id', $bookingIds)
            ->get(['final_price', 'estimated_price', 'platform_commission_amount']);
        $gross = 0;
        $commission = 0;
        foreach ($bookings as $booking) {
            $gross += PaymentService::legacyAmountToRials((string) ($booking->final_price ?? $booking->estimated_price));
            $commission += PaymentService::legacyAmountToRials((string) $booking->platform_commission_amount);
        }
        $paidEarnings = (int) DB::table('wallet_transactions')
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->where('wallets.user_id', $profile->user_id)
            ->whereIn('wallet_transactions.type', ['earning', 'earning_reversed'])
            ->selectRaw("COALESCE(SUM(CASE WHEN wallet_transactions.type = 'earning' THEN wallet_transactions.amount_minor ELSE -wallet_transactions.amount_minor END), 0) as amount")
            ->value('amount');
        $net = $gross - $commission;
        $settlements = DB::table('technician_settlements')
            ->where('technician_id', $profile->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['settlement_uuid', 'amount_minor', 'currency', 'status', 'bank_name', 'created_at', 'settled_at']);
        $walletBalance = (int) DB::table('wallets')->where('user_id', $profile->user_id)->value('balance_minor');

        return response()->json([
            'data' => [
                'currency' => 'IRR',
                'unit' => 'rial',
                'gross' => $gross,
                'platform_commission' => $commission,
                'net_earnings' => $net,
                'paid_earnings_minor' => $paidEarnings,
                'available_balance_minor' => $walletBalance,
                'minimum_settlement_minor' => 100000,
                'daily_settlement_limit_minor' => 500000000,
                'settlements' => $settlements,
            ],
        ]);
    }

    private function profileData(object $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'name' => DB::table('users')->where('id', $profile->user_id)->value('name'),
            'status' => $profile->status,
            'online' => (bool) $profile->is_online,
            'available' => (bool) $profile->is_available,
            'license_number' => $profile->license_number,
            'guild_registration_no' => $profile->guild_registration_no,
            'vehicle_type' => $profile->vehicle_type,
            'vehicle_plate' => $profile->vehicle_plate,
            'documents' => DB::table('technician_documents')
                ->where('technician_id', $profile->id)
                ->orderByDesc('id')
                ->get(['id', 'document_type', 'status', 'rejection_reason', 'reviewed_at']),
            'service_ids' => DB::table('technician_services')->where('technician_id', $profile->id)->pluck('service_id'),
            'service_area_ids' => DB::table('technician_service_areas')->where('technician_id', $profile->id)->pluck('service_area_id'),
            'working_hours' => DB::table('technician_working_hours')->where('technician_id', $profile->id)
                ->orderBy('day_of_week')->orderBy('starts_at')->get([
                    'day_of_week', 'starts_at', 'ends_at', 'capacity', 'is_active',
                ]),
        ];
    }

    private function technicianProfile(Request $request, bool $verifiedOnly = true): ?object
    {
        $user = $request->user();
        if (! $user || $user->status !== 'active' ||
            ! $this->hasRole((int) $user->id, 'technician')) {
            return null;
        }
        $query = DB::table('technician_profiles')->where('user_id', $user->id);
        if ($verifiedOnly) {
            $query->where('status', 'verified');
        }

        return $query->first();
    }

    private function hasRole(int $userId, string $role): bool
    {
        return DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $userId)
            ->where('roles.name', $role)
            ->exists();
    }

    private function hasPermission(int $userId, string $permission, array $roles): bool
    {
        return DB::table('permission_role')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->join('users', 'role_user.user_id', '=', 'users.id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->where('role_user.user_id', $userId)
            ->where('users.status', 'active')
            ->whereIn('roles.name', $roles)
            ->where('permissions.name', $permission)
            ->exists();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return match (DB::getDriverName()) {
            'mysql' => $driverCode === 1062,
            'pgsql' => $sqlState === '23505',
            'sqlite' => $driverCode === 19 && str_contains($message, 'unique constraint'),
            default => $sqlState === '23505' ||
                ($sqlState === '23000' && str_contains($message, 'unique')),
        };
    }

    private function eligibleForBooking(object $profile, object $booking): bool
    {
        $estimate = $booking->price_estimate_id
            ? DB::table('price_estimates')->where('id', $booking->price_estimate_id)->first()
            : null;

        return $estimate &&
            DB::table('technician_services')
                ->join('services', 'technician_services.service_id', '=', 'services.id')
                ->where('technician_services.technician_id', $profile->id)
                ->where('technician_services.service_id', $booking->service_id)
                ->where('services.is_active', true)
                ->exists() &&
            DB::table('technician_service_areas')
                ->join('service_areas', 'technician_service_areas.service_area_id', '=', 'service_areas.id')
                ->where('technician_service_areas.technician_id', $profile->id)
                ->where('technician_service_areas.service_area_id', $estimate->service_area_id)
                ->where('service_areas.is_active', true)
                ->exists();
    }

    private function capacityForBooking(int $technicianId, object $booking): ?int
    {
        $at = $booking->scheduled_at ? Carbon::parse($booking->scheduled_at) : now();
        $slot = DB::table('technician_working_hours')
            ->where('technician_id', $technicianId)
            ->where('day_of_week', $at->dayOfWeek)
            ->where('is_active', true)
            ->where('starts_at', '<=', $at->format('H:i:s'))
            ->where('ends_at', '>', $at->format('H:i:s'))
            ->orderByDesc('capacity')
            ->first();

        return $slot ? (int) $slot->capacity : null;
    }

    private function activeAssignmentCount(int $technicianId): int
    {
        return DB::table('assignments')
            ->join('bookings', 'assignments.booking_id', '=', 'bookings.id')
            ->where('assignments.technician_id', $technicianId)
            ->where('assignments.status', 'active')
            ->whereIn('bookings.status', ['assigned', 'en_route', 'arrived', 'in_progress', 'payment_pending'])
            ->count();
    }

    private function dispatchError(string $error): JsonResponse
    {
        $messages = [
            'BOOKING_NOT_FOUND' => [404, 'سفارش برای اعزام پیدا نشد.'],
            'BOOKING_NOT_DISPATCHABLE' => [409, 'سفارش در وضعیت فعلی قابل اعزام نیست.'],
            'TECHNICIAN_NOT_ELIGIBLE' => [422, 'همهٔ متخصصان انتخاب‌شده تأییدشده، آنلاین و واجد صلاحیت این سفارش نیستند.'],
            'TECHNICIAN_CAPACITY_REACHED' => [409, 'ظرفیت یا ساعت کاری متخصص برای این سفارش کافی نیست.'],
            'SERVICE_AREA_UNAVAILABLE' => [422, 'محدودهٔ خدمت این سفارش مشخص نیست؛ امکان اعزام امن وجود ندارد.'],
            'INVALID_STATUS_TRANSITION' => [409, 'انتقال سفارش به صف اعزام مجاز نیست.'],
        ];
        [$status, $message] = $messages[$error] ?? [409, 'ایجاد پیشنهاد اعزام ممکن نشد.'];

        return $this->error($error, $message, $status);
    }

    private function offerError(string $error): JsonResponse
    {
        $messages = [
            'OFFER_NOT_FOUND' => [404, 'پیشنهاد مأموریت پیدا نشد.'],
            'OFFER_ALREADY_RESPONDED' => [409, 'به این پیشنهاد قبلاً پاسخ داده شده است.'],
            'OFFER_EXPIRED' => [409, 'مهلت پاسخ‌گویی به پیشنهاد پایان یافته است.'],
            'BOOKING_ALREADY_ASSIGNED' => [409, 'این مأموریت قبلاً به متخصص دیگری تخصیص یافته است.'],
            'TECHNICIAN_NOT_VERIFIED' => [403, 'پس از لغو تأیید مدارک امکان دریافت مأموریت وجود ندارد.'],
            'TECHNICIAN_NOT_AVAILABLE' => [409, 'برای پذیرش مأموریت باید آنلاین و آزاد باشید.'],
            'TECHNICIAN_NOT_ELIGIBLE' => [403, 'این مأموریت با مهارت یا محدودهٔ کاری شما تطبیق ندارد.'],
            'TECHNICIAN_CAPACITY_REACHED' => [409, 'ظرفیت یا ساعت کاری شما اجازهٔ پذیرش این مأموریت را نمی‌دهد.'],
        ];
        [$status, $message] = $messages[$error] ?? [409, 'پاسخ به پیشنهاد مأموریت ممکن نشد.'];

        return $this->error($error, $message, $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $code, 'message' => $message], $status);
    }
}
