<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Services\BookingLifecycleService;
use App\Modules\Booking\Services\TariffPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PricingController extends Controller
{
    private const AUTHORIZATION_ATTESTATION_VERSION = 'keypro-entry-authority-v1';

    public function estimate(Request $request, TariffPricingService $pricing): JsonResponse
    {
        $customer = $this->customerProfile($request);
        if (!$customer) {
            return $this->forbiddenCustomer();
        }

        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90', 'decimal:0,7'],
            'longitude' => ['required', 'numeric', 'between:-180,180', 'decimal:0,7'],
            'urgency' => ['sometimes', 'required', Rule::in(['urgent', 'scheduled'])],
            'scheduled_at' => ['nullable', 'required_if:urgency,scheduled', 'date', 'after_or_equal:now'],
        ]);
        $urgency = $validated['urgency'] ?? (isset($validated['scheduled_at']) ? 'scheduled' : 'urgent');
        if (($validated['urgency'] ?? null) === 'urgent' && isset($validated['scheduled_at'])) {
            return response()->json([
                'success' => false,
                'error' => 'URGENCY_SCHEDULE_MISMATCH',
                'message' => 'برای درخواست فوری، زمان رزرو جداگانه انتخاب نکنید.',
            ], 422);
        }
        $quote = $pricing->quote(
            (int) $validated['service_id'],
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            $validated['scheduled_at'] ?? null
        );
        $estimateUuid = (string) Str::uuid();
        $expiresAt = now()->addMinutes(15);
        $snapshot = $quote['snapshot'];
        $snapshot['estimate_expires_at'] = $expiresAt->toIso8601String();
        $snapshot['currency'] = 'IRR';
        $snapshot['approximate'] = true;
        $snapshot['urgency'] = $urgency;
        $snapshot['minimum_total'] = $quote['minimum_total'];
        $snapshot['maximum_total'] = $quote['maximum_total'];

        DB::table('price_estimates')->insert([
            'estimate_uuid' => $estimateUuid,
            'user_id' => $request->user()->id,
            'service_id' => $quote['service']->id,
            'service_area_id' => $quote['area']->id,
            'service_tariff_id' => $quote['tariff']->id,
            'tariff_version' => $quote['tariff']->version,
            'pickup_lat' => $validated['latitude'],
            'pickup_lng' => $validated['longitude'],
            'estimated_distance_km' => $quote['distance_km'],
            'base_fare' => $quote['base_fare'],
            'distance_fare' => $quote['distance_fare'],
            'surge_amount' => $quote['night_surcharge'],
            'dispatch_fee' => $quote['dispatch_fee'],
            'time_fare' => $quote['time_fare'],
            'minimum_trip_km' => $quote['snapshot']['minimum_trip_km'],
            'min_total_price' => $quote['minimum_total'],
            'max_total_price' => $quote['maximum_total'],
            'pricing_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'estimate_id' => $estimateUuid,
                'service' => [
                    'id' => $quote['service']->id,
                    'name' => $quote['service']->title_fa,
                ],
                'service_area' => $snapshot['service_area_name'],
                'tariff_version' => $quote['tariff']->version,
                'urgency' => $urgency,
                'scheduled_at' => $urgency === 'scheduled' ? $snapshot['effective_at'] : null,
                'approximate' => true,
                'currency' => 'IRR',
                'components' => $snapshot['components'],
                'estimated_subtotal' => $quote['subtotal'],
                'minimum_total' => $quote['minimum_total'],
                'maximum_total' => $quote['maximum_total'],
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ], 201);
    }

    public function createBooking(Request $request, BookingLifecycleService $lifecycle): JsonResponse
    {
        $customer = $this->customerProfile($request);
        if (!$customer) {
            return $this->forbiddenCustomer();
        }

        $validated = $request->validate([
            'estimate_id' => ['required', 'uuid'],
            'street_address' => ['required', 'string', 'max:2000'],
            'problem_description' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['sometimes', 'required', 'uuid'],
            'ownership_attested' => ['sometimes', 'boolean'],
            'property_type' => ['nullable', Rule::in(['residential', 'commercial', 'vehicle', 'safe'])],
        ]);

        $headerKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $requestKey = $validated['idempotency_key'] ?? $headerKey;
        if (
            ($headerKey !== null && $requestKey !== $headerKey) ||
            ($request->header('Idempotency-Key') !== null &&
                $request->header('X-Idempotency-Key') !== null &&
                $request->header('Idempotency-Key') !== $request->header('X-Idempotency-Key'))
        ) {
            return response()->json([
                'success' => false,
                'error' => 'IDEMPOTENCY_KEY_MISMATCH',
                'message' => 'شناسهٔ یکتای درخواست در سربرگ و بدنه باید یکسان باشد.',
            ], 422);
        }
        if ($requestKey !== null && !Str::isUuid($requestKey)) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_IDEMPOTENCY_KEY',
                'message' => 'شناسهٔ یکتای درخواست باید UUID معتبر باشد.',
            ], 422);
        }
        if (trim($validated['street_address']) === '') {
            return response()->json([
                'success' => false,
                'error' => 'ADDRESS_REQUIRED',
                'message' => 'نشانی خدمت را به‌درستی وارد کنید.',
            ], 422);
        }

        $requestPayloadHash = hash('sha256', json_encode([
            'estimate_id' => $validated['estimate_id'],
            'street_address' => trim($validated['street_address']),
            'problem_description' => $validated['problem_description'] ?? null,
            'ownership_attested' => (bool) ($validated['ownership_attested'] ?? false),
            'property_type' => $validated['property_type'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            $result = DB::transaction(function () use (
                $request,
                $customer,
                $validated,
                $requestKey,
                $requestPayloadHash,
                $lifecycle
            ): array {
            if ($requestKey) {
                $existingByKey = DB::table('bookings')
                    ->where('customer_id', $customer->id)
                    ->where('request_idempotency_key', $requestKey)
                    ->lockForUpdate()
                    ->first();
                if ($existingByKey) {
                    if (!hash_equals((string) $existingByKey->request_payload_hash, $requestPayloadHash)) {
                        return ['error' => 'IDEMPOTENCY_KEY_REUSED'];
                    }

                    return ['booking' => $existingByKey, 'created' => false];
                }
            }

            $estimate = DB::table('price_estimates')
                ->where('estimate_uuid', $validated['estimate_id'])
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();
            if (!$estimate) {
                return ['error' => 'ESTIMATE_NOT_FOUND'];
            }

            $existing = DB::table('bookings')->where('price_estimate_id', $estimate->id)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->id) {
                    return ['error' => 'ESTIMATE_ALREADY_USED'];
                }
                if (
                    $existing->request_payload_hash !== null &&
                    !hash_equals((string) $existing->request_payload_hash, $requestPayloadHash)
                ) {
                    return ['error' => 'ESTIMATE_ALREADY_USED'];
                }

                return ['booking' => $existing, 'created' => false];
            }
            if (now()->greaterThan($estimate->expires_at)) {
                return ['error' => 'ESTIMATE_EXPIRED'];
            }

            $service = DB::table('services')->where('id', $estimate->service_id)->first();
            if (!$service || !$service->is_active) {
                return ['error' => 'SERVICE_INACTIVE'];
            }

            $requiresAuthorization = (bool) $service->requires_ownership_proof;
            if ($requiresAuthorization && (
                !($validated['ownership_attested'] ?? false) ||
                empty($validated['property_type'])
            )) {
                return ['error' => 'CUSTOMER_AUTHORIZATION_REQUIRED'];
            }

            $snapshot = json_decode($estimate->pricing_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $snapshot['estimate_range'] = [
                'minimum_total' => (float) $estimate->min_total_price,
                'maximum_total' => (float) $estimate->max_total_price,
                'approximate' => true,
            ];
            $urgency = $snapshot['urgency'] ?? 'urgent';
            $scheduledAt = $urgency === 'scheduled' ? ($snapshot['effective_at'] ?? null) : null;
            $now = now();
            $bookingId = DB::table('bookings')->insertGetId([
                'booking_code' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'request_idempotency_key' => $requestKey,
                'request_payload_hash' => $requestPayloadHash,
                'service_id' => $estimate->service_id,
                'price_estimate_id' => $estimate->id,
                'service_tariff_id' => $estimate->service_tariff_id,
                'tariff_version' => $estimate->tariff_version,
                'pricing_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => 'draft',
                'urgency' => $urgency,
                'latitude' => $estimate->pickup_lat,
                'longitude' => $estimate->pickup_lng,
                'street_address' => trim($validated['street_address']),
                'problem_description' => $validated['problem_description'] ?? null,
                'estimated_price' => $snapshot['estimated_subtotal'],
                'scheduled_at' => $scheduledAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $lifecycle->recordInitialStatus($bookingId, (int) $request->user()->id);

            if ($requiresAuthorization) {
                DB::table('booking_authorizations')->insert([
                    'booking_id' => $bookingId,
                    'customer_id' => $customer->id,
                    'property_type' => $validated['property_type'],
                    'attestation_version' => self::AUTHORIZATION_ATTESTATION_VERSION,
                    'status' => 'customer_attested',
                    'customer_ip' => $request->ip(),
                    'attested_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $booking = DB::table('bookings')->find($bookingId);

            return ['booking' => $booking, 'created' => true];
            }, 3);
        } catch (QueryException $exception) {
            $racedBooking = $requestKey
                ? DB::table('bookings')
                    ->where('customer_id', $customer->id)
                    ->where('request_idempotency_key', $requestKey)
                    ->first()
                : null;
            $racedBooking ??= DB::table('bookings')
                    ->join('price_estimates', 'bookings.price_estimate_id', '=', 'price_estimates.id')
                    ->where('price_estimates.estimate_uuid', $validated['estimate_id'])
                    ->where('bookings.customer_id', $customer->id)
                    ->first(['bookings.*']);
            if (!$racedBooking) {
                throw $exception;
            }
            if (
                $racedBooking->request_payload_hash !== null &&
                !hash_equals((string) $racedBooking->request_payload_hash, $requestPayloadHash)
            ) {
                $result = ['error' => $requestKey ? 'IDEMPOTENCY_KEY_REUSED' : 'ESTIMATE_ALREADY_USED'];
            } else {
                $result = ['booking' => $racedBooking, 'created' => false];
            }
        }

        if (isset($result['error'])) {
            $responses = [
                'ESTIMATE_NOT_FOUND' => [404, 'برآورد معتبر برای این حساب پیدا نشد.'],
                'ESTIMATE_EXPIRED' => [422, 'اعتبار برآورد پایان یافته است؛ برآورد تازه دریافت کنید.'],
                'ESTIMATE_ALREADY_USED' => [409, 'این برآورد قبلاً برای درخواست دیگری استفاده شده است.'],
                'IDEMPOTENCY_KEY_REUSED' => [409, 'این شناسهٔ یکتا قبلاً با محتوای متفاوتی استفاده شده است.'],
                'SERVICE_INACTIVE' => [422, 'این خدمت دیگر فعال نیست.'],
                'CUSTOMER_AUTHORIZATION_REQUIRED' => [422, 'برای درخواست خدمت، تأیید اختیار دسترسی به ملک یا خودرو لازم است.'],
            ];
            [$status, $message] = $responses[$result['error']];

            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'message' => $message,
            ], $status);
        }

        return response()->json([
            'data' => [
                'booking_id' => $result['booking']->id,
                'booking_code' => $result['booking']->booking_code,
                'status' => $result['booking']->status,
                'urgency' => $result['booking']->urgency,
                'estimated_price' => (float) $result['booking']->estimated_price,
                'tariff_version' => $result['booking']->tariff_version,
                'pricing_snapshot' => json_decode($result['booking']->pricing_snapshot, true, flags: JSON_THROW_ON_ERROR),
                'authorization_status' => isset($result['booking']->id)
                    ? DB::table('booking_authorizations')->where('booking_id', $result['booking']->id)->value('status')
                    : null,
                'next_step' => 'پیش از هرگونه اقدام در محل، متخصص باید اختیار مشتری را نیز بررسی کند.',
            ],
            'already_created' => !$result['created'],
        ], $result['created'] ? 201 : 200);
    }

    private function customerProfile(Request $request): ?object
    {
        $user = $request->user();
        if (!$user || $user->status !== 'active') {
            return null;
        }

        $permitted = DB::table('permission_role')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->where('role_user.user_id', $user->id)
            ->where('roles.name', 'customer')
            ->where('permissions.name', 'bookings.create')
            ->exists();
        if (!$permitted) {
            return null;
        }

        return DB::table('customer_profiles')->where('user_id', $user->id)->first();
    }

    private function forbiddenCustomer(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'FORBIDDEN_BOOKING_CREATE',
            'message' => 'ثبت برآورد و درخواست فقط برای مشتری فعال دارای مجوز امکان‌پذیر است.',
        ], 403);
    }
}
