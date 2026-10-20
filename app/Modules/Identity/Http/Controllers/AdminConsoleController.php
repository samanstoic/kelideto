<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FinancialLedger\Http\Controllers\FinanceController;
use App\Modules\Identity\Services\LocationAccessWindow;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminConsoleController extends Controller
{
    private const RESOURCES = [
        'categories' => ['table' => 'service_categories', 'search' => ['name_fa', 'slug']],
        'services' => ['table' => 'services', 'search' => ['title_fa', 'slug', 'description']],
        'areas' => ['table' => 'service_areas', 'search' => ['city_name', 'zone_name', 'slug']],
        'pricing' => ['table' => 'pricing_rules', 'search' => ['rule_name']],
        'tariffs' => ['table' => 'service_tariffs', 'search' => ['id', 'service_id']],
        'content' => ['table' => 'content_pages', 'search' => ['slug', 'title', 'body', 'meta_description']],
        'settings' => ['table' => 'app_settings', 'search' => ['setting_key', 'category']],
        'tickets' => ['table' => 'support_tickets', 'search' => ['ticket_code', 'subject', 'category', 'status']],
        'templates' => ['table' => 'notification_templates', 'search' => ['event_key', 'channel', 'body_fa']],
    ];

    private const ADMIN_PERMISSIONS = [
        'admin.dashboard.view', 'admin.users.manage', 'admin.technicians.manage',
        'admin.bookings.view', 'admin.map.view', 'admin.catalog.manage',
        'admin.support.manage', 'admin.content.manage', 'admin.settings.manage',
        'admin.audit.view', 'admin.reports.view', 'admin.access.manage',
    ];

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.dashboard.view');

        return response()->json([
            'success' => true,
            'data' => [
                'users' => DB::table('users')->count(),
                'active_users' => DB::table('users')->where('status', 'active')->count(),
                'technicians_pending' => DB::table('technician_profiles')->where('status', 'pending_verification')->count(),
                'technicians_verified' => DB::table('technician_profiles')->where('status', 'verified')->count(),
                'open_bookings' => DB::table('bookings')->whereIn('status', ['broadcasting', 'assigned', 'en_route', 'arrived', 'in_progress'])->count(),
                'open_tickets' => DB::table('support_tickets')->whereIn('status', ['open', 'in_progress'])->count(),
                'payments_pending_settlement' => DB::table('technician_settlements')->where('status', 'pending')->count(),
                'recent_bookings' => DB::table('bookings')->select(['id', 'booking_code', 'status', 'created_at'])
                    ->latest('id')->limit(8)->get(),
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }

    public function exportReports(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.reports.view');
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $rows = DB::table('bookings')
            ->when(isset($data['from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $data['from']))
            ->when(isset($data['to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $data['to']))
            ->select(['booking_code', 'status', 'estimated_price', 'final_price', 'created_at'])
            ->orderBy('id')->limit(10000)->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.users.manage');
        $query = DB::table('users')->select(['id', 'name', 'email', 'phone_number', 'status', 'created_at'])
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';
                $query->where(function (Builder $query) use ($term) {
                    $query->where('name', 'like', $term)->orWhere('email', 'like', $term)
                        ->orWhere('phone_number', 'like', $term);
                });
            })->orderByDesc('id');

        $users = $query->paginate($this->pageSize($request));
        $users->getCollection()->transform(function (object $user): object {
            $user->roles = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $user->id)->pluck('roles.name');

            return $user;
        });

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function updateUser(Request $request, int $userId): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.users.manage');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', 'exists:roles,name'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        $this->reauthenticate($actor, $data['password'] ?? null, $request);

        $user = DB::transaction(function () use ($actor, $userId, $data, $request) {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            abort_unless($user, 404, 'کاربر پیدا نشد.');
            abort_if((int) $actor->id === $userId, 422, 'تغییر نقش یا تعلیق حساب خودتان مجاز نیست.');
            $old = ['status' => $user->status, 'roles' => $this->roleNames($userId)];
            $accessChanged = isset($data['status']) && $data['status'] !== $user->status;

            if (isset($data['status'])) {
                DB::table('users')->where('id', $userId)->update(['status' => $data['status'], 'updated_at' => now()]);
            }
            if (isset($data['roles'])) {
                $requestedRoles = $data['roles'];
                $currentRoles = $old['roles'];
                sort($requestedRoles);
                sort($currentRoles);
                abort_unless($requestedRoles === $currentRoles || $this->isSuperAdmin($actor->id), 403,
                    'تغییر نقش فقط برای مدیر ارشد مجاز است.');
                abort_if($this->isSuperAdmin($userId) && ! $this->isSuperAdmin($actor->id), 403);
                if ($requestedRoles !== $currentRoles) {
                    $roleIds = DB::table('roles')->whereIn('name', $data['roles'])->pluck('id');
                    DB::table('role_user')->where('user_id', $userId)->delete();
                    foreach ($roleIds as $roleId) {
                        DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $roleId]);
                    }
                    $accessChanged = true;
                }
            }
            if ($accessChanged) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', \App\Models\User::class)
                    ->where('tokenable_id', $userId)
                    ->delete();
            }
            $stillTechnician = DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $userId)
                ->where('roles.name', 'technician')
                ->exists();
            if (($data['status'] ?? $user->status) !== 'active' || ! $stillTechnician) {
                DB::table('technician_profiles')->where('user_id', $userId)->update([
                    'is_online' => false,
                    'is_available' => false,
                    'current_lat' => null,
                    'current_lng' => null,
                    'last_telemetry_at' => null,
                    'updated_at' => now(),
                ]);
            }
            $new = [
                'status' => $data['status'] ?? $user->status,
                'roles' => $this->roleNames($userId),
            ];
            $this->audit($request, $actor->id, 'admin.user.updated', 'user', $userId, $old, $new, $data['reason']);

            return DB::table('users')->where('id', $userId)->first();
        });

        return response()->json(['success' => true, 'data' => $user]);
    }

    public function roles(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.users.manage');

        return response()->json(['success' => true, 'data' => DB::table('roles')->orderBy('name')->get()]);
    }

    public function technicians(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.technicians.manage');
        $query = DB::table('technician_profiles')
            ->join('users', 'users.id', '=', 'technician_profiles.user_id')
            ->select(['technician_profiles.id', 'technician_profiles.user_id', 'technician_profiles.status',
                'technician_profiles.is_online', 'technician_profiles.is_available', 'users.name',
                'users.phone_number', 'technician_profiles.created_at'])
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';
                $query->where(function (Builder $query) use ($term) {
                    $query->where('users.name', 'like', $term)->orWhere('users.phone_number', 'like', $term);
                });
            })->orderByDesc('technician_profiles.id');

        return response()->json(['success' => true, 'data' => $query->paginate($this->pageSize($request))]);
    }

    public function updateTechnician(Request $request, int $technicianId): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.technicians.manage');
        $data = $request->validate([
            'status' => ['required', Rule::in(['verified', 'suspended', 'rejected'])],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        $this->reauthenticate($actor, $data['password'] ?? null, $request);

        $profile = DB::transaction(function () use ($actor, $technicianId, $data, $request) {
            $profile = DB::table('technician_profiles')->where('id', $technicianId)->lockForUpdate()->first();
            abort_unless($profile, 404, 'پروفایل متخصص پیدا نشد.');
            if ($data['status'] === 'verified') {
                $requiredDocuments = ['national_id', 'guild_license'];
                foreach ($requiredDocuments as $type) {
                    abort_unless(DB::table('technician_documents')
                        ->where('technician_id', $technicianId)->where('document_type', $type)
                        ->where('status', 'approved')->exists(), 422, 'مدارک الزامی متخصص تأیید نشده‌اند.');
                }
            }
            DB::table('technician_profiles')->where('id', $technicianId)->update([
                'status' => $data['status'],
                'is_online' => $data['status'] === 'verified' ? $profile->is_online : false,
                'is_available' => $data['status'] === 'verified' ? $profile->is_available : false,
                'updated_at' => now(),
            ]);
            $this->audit($request, $actor->id, 'admin.technician.status_changed', 'technician_profile',
                $technicianId, ['status' => $profile->status], ['status' => $data['status']], $data['reason']);

            return DB::table('technician_profiles')->where('id', $technicianId)->first();
        });

        return response()->json(['success' => true, 'data' => $profile]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.bookings.view');
        $query = DB::table('bookings')->join('customer_profiles', 'customer_profiles.id', '=', 'bookings.customer_id')
            ->join('users as customers', 'customers.id', '=', 'customer_profiles.user_id')
            ->leftJoin('technician_profiles', 'technician_profiles.id', '=', 'bookings.technician_id')
            ->leftJoin('users as technicians', 'technicians.id', '=', 'technician_profiles.user_id')
            ->select(['bookings.id', 'bookings.booking_code', 'bookings.status', 'bookings.created_at',
                'bookings.scheduled_at', 'bookings.estimated_price', 'customers.name as customer_name',
                'technicians.name as technician_name'])
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';
                $query->where(function (Builder $query) use ($term) {
                    $query->where('bookings.booking_code', 'like', $term)->orWhere('bookings.status', 'like', $term)
                        ->orWhere('customers.name', 'like', $term)->orWhere('technicians.name', 'like', $term);
                });
            })->orderByDesc('bookings.id');

        return response()->json(['success' => true, 'data' => $query->paginate($this->pageSize($request))]);
    }

    public function operationsMap(Request $request, LocationAccessWindow $locationAccessWindow): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.map.view');
        $retentionHours = $locationAccessWindow->hours();
        $rows = DB::table('bookings')->join('assignments', 'assignments.booking_id', '=', 'bookings.id')
            ->where('assignments.status', 'active')
            ->whereIn('bookings.status', ['en_route', 'arrived', 'in_progress'])
            ->where('assignments.assigned_at', '>=', now()->subHours($retentionHours))
            ->whereNotNull('bookings.technician_id')
            ->select(['bookings.id', 'bookings.booking_code', 'bookings.status', 'bookings.latitude',
                'bookings.longitude', 'bookings.street_address', 'assignments.technician_id', 'assignments.assigned_at'])
            ->orderByDesc('assignments.assigned_at')->limit(200)->get();
        $this->audit($request, $actor->id, 'admin.map.viewed', 'booking', null, null,
            ['count' => $rows->count()], 'مشاهده مأموریت‌های فعال مجاز');

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function resourceIndex(Request $request, string $resource): JsonResponse
    {
        $permission = $this->resourcePermission($resource);
        $this->authorizePermission($request, $permission);
        $config = $this->resourceConfig($resource);
        $query = DB::table($config['table'])->when($request->filled('q'), function (Builder $query) use ($request, $config) {
            $term = '%'.$request->string('q')->toString().'%';
            $query->where(function (Builder $query) use ($term, $config) {
                foreach ($config['search'] as $column) {
                    $query->orWhere($column, 'like', $term);
                }
            });
        });
        if ($resource === 'settings') {
            $query->where('is_sensitive', false)->select(['id', 'setting_key', 'setting_value', 'category', 'updated_at']);
        }
        if ($resource === 'content') {
            $query->whereNull('deleted_at');
        }
        if ($resource === 'templates') {
            $query->select(['id', 'event_key', 'channel', 'title_fa', 'body_fa', 'is_active', 'updated_at']);
        }
        $page = $query->orderByDesc('id')->paginate($this->pageSize($request));
        $page->getCollection()->transform(function (object $record) use ($resource): object {
            if ($resource === 'settings' && isset($record->setting_value)) {
                $record->setting_value = json_decode($record->setting_value, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($resource === 'areas' && isset($record->polygon_geojson) && is_string($record->polygon_geojson)) {
                $record->polygon_geojson = json_decode($record->polygon_geojson, true, 512, JSON_THROW_ON_ERROR);
            }

            return $record;
        });

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function storeResource(Request $request, string $resource): JsonResponse
    {
        $actor = $this->authorizePermission($request, $this->resourcePermission($resource));
        if ($resource === 'settings') {
            $stepUp = $request->validate([
                'password' => ['sometimes', 'nullable', 'string'],
                'confirmation' => ['required', 'in:CONFIRM'],
            ]);
            $this->reauthenticate($actor, $stepUp['password'] ?? null, $request);
        }
        $payload = $this->validatedResource($request, $resource, true);
        if ($resource === 'settings') {
            $this->assertSafeSettingsPayload($payload);
        }
        $table = $this->resourceConfig($resource)['table'];
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        if ($resource === 'content') {
            $payload['created_by_user_id'] = $actor->id;
            $payload['updated_by_user_id'] = $actor->id;
            $payload = $this->normalizeContentPublication($payload);
        } elseif ($resource === 'settings') {
            $payload['updated_by_user_id'] = $actor->id;
            $payload['is_sensitive'] = false;
        }
        $new = DB::transaction(function () use ($table, $payload, $actor, $request) {
            $id = DB::table($table)->insertGetId($payload);
            $new = DB::table($table)->where('id', $id)->first();
            $this->audit($request, $actor->id, 'admin.resource.created', $table, $id, null, (array) $new,
                $request->string('reason')->toString());

            return $new;
        });
        $this->invalidatePublicCatalogForResource($resource);

        return response()->json(['success' => true, 'data' => $new], 201);
    }

    public function updateResource(Request $request, string $resource, int $id): JsonResponse
    {
        $actor = $this->authorizePermission($request, $this->resourcePermission($resource));
        if ($resource === 'settings') {
            $stepUp = $request->validate([
                'password' => ['sometimes', 'nullable', 'string'],
                'confirmation' => ['required', 'in:CONFIRM'],
            ]);
            $this->reauthenticate($actor, $stepUp['password'] ?? null, $request);
        }
        $payload = $this->validatedResource($request, $resource, false, $id);
        if ($resource === 'settings') {
            $this->assertSafeSettingsPayload($payload);
        }
        $table = $this->resourceConfig($resource)['table'];
        if ($resource === 'content') {
            $payload['updated_by_user_id'] = $actor->id;
        }
        if ($resource === 'settings') {
            $payload['updated_by_user_id'] = $actor->id;
        }
        $new = DB::transaction(function () use ($table, $id, $payload, $actor, $request, $resource) {
            $old = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404, 'مورد پیدا نشد.');
            if ($resource === 'content') {
                $payload = $this->normalizeContentPublication($payload, $old);
            }
            $payload['updated_at'] = now();
            DB::table($table)->where('id', $id)->update($payload);
            $new = DB::table($table)->where('id', $id)->first();
            $oldPath = $this->managedPublicPath($resource, $old);
            $newPath = $this->managedPublicPath($resource, $new);
            if ($oldPath && $newPath && $oldPath !== $newPath) {
                DB::table('content_redirects')->where('target_path', $oldPath)->where('source_path', '<>', $newPath)
                    ->update(['target_path' => $newPath, 'updated_at' => now()]);
                DB::table('content_redirects')->where('source_path', $newPath)->delete();
                DB::table('content_redirects')->updateOrInsert(
                    ['source_path' => $oldPath],
                    [
                        'target_path' => $newPath,
                        'status_code' => 301,
                        'is_active' => true,
                        'created_by_user_id' => $actor->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
            $this->audit($request, $actor->id, 'admin.resource.updated', $table, $id, (array) $old, (array) $new,
                $request->string('reason')->toString());

            return $new;
        });
        $this->invalidatePublicCatalogForResource($resource);

        return response()->json(['success' => true, 'data' => $new]);
    }

    public function deleteContent(Request $request, int $id): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.content.manage');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        $this->reauthenticate($actor, $data['password'] ?? null, $request);
        DB::transaction(function () use ($id, $actor, $request, $data) {
            $page = DB::table('content_pages')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($page, 404, 'صفحه پیدا نشد.');
            DB::table('content_pages')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            $this->audit($request, $actor->id, 'admin.content.deleted', 'content_page', $id, (array) $page,
                ['deleted_at' => now()->toIso8601String()], $data['reason']);
        });

        return response()->json(['success' => true]);
    }

    public function ticketMessages(Request $request, int $ticketId): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.support.manage');
        abort_unless(DB::table('support_tickets')->where('id', $ticketId)->exists(), 404);
        $messages = DB::table('support_ticket_messages')
            ->where('ticket_id', $ticketId)->orderBy('id')
            ->select(['id', 'ticket_id', 'sender_user_id', 'message', 'created_at'])
            ->paginate($this->pageSize($request));
        $this->audit($request, $actor->id, 'admin.ticket.messages_viewed', 'support_ticket', $ticketId,
            null, ['page' => $messages->currentPage(), 'items' => $messages->count()], 'مشاهده پیام‌های تیکت پشتیبانی');

        return response()->json(['success' => true, 'data' => $messages]);
    }

    public function createTicket(Request $request): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.support.manage');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'subject' => ['required', 'string', 'min:3', 'max:255'],
            'category' => ['required', Rule::in(['payment_dispute', 'property_damage', 'late_arrival', 'misconduct', 'other'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'emergency'])],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        if (isset($data['booking_id'])) {
            $belongsToUser = DB::table('bookings')
                ->join('customer_profiles', 'customer_profiles.id', '=', 'bookings.customer_id')
                ->where('bookings.id', $data['booking_id'])->where('customer_profiles.user_id', $data['user_id'])
                ->exists();
            abort_unless($belongsToUser, 422, 'سفارش انتخاب‌شده متعلق به این کاربر نیست.');
        }
        $ticket = DB::transaction(function () use ($actor, $request, $data) {
            $id = DB::table('support_tickets')->insertGetId([
                'ticket_code' => (string) Str::uuid(),
                'user_id' => $data['user_id'],
                'booking_id' => $data['booking_id'] ?? null,
                'subject' => $data['subject'],
                'priority' => $data['priority'],
                'status' => 'open',
                'category' => $data['category'],
                'assigned_agent_id' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('support_ticket_messages')->insert([
                'ticket_id' => $id, 'sender_user_id' => $actor->id,
                'message' => $data['message'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $ticket = DB::table('support_tickets')->where('id', $id)->first();
            $this->audit($request, $actor->id, 'admin.ticket.created', 'support_ticket', $id, null,
                ['ticket_code' => $ticket->ticket_code, 'subject' => $ticket->subject, 'user_id' => $ticket->user_id],
                $data['reason']);

            return $ticket;
        });

        return response()->json(['success' => true, 'data' => $ticket], 201);
    }

    public function updateTicket(Request $request, int $ticketId): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.support.manage');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'emergency'])],
            'assigned_agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'message' => ['sometimes', 'string', 'min:1', 'max:5000'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $ticket = DB::transaction(function () use ($ticketId, $actor, $request, $data) {
            $ticket = DB::table('support_tickets')->where('id', $ticketId)->lockForUpdate()->first();
            abort_unless($ticket, 404, 'تیکت پیدا نشد.');
            $old = (array) $ticket;
            $changes = array_intersect_key($data, array_flip(['status', 'priority', 'assigned_agent_id']));
            if (array_key_exists('assigned_agent_id', $changes) && $changes['assigned_agent_id'] !== null) {
                $agentRole = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->join('users', 'users.id', '=', 'role_user.user_id')
                    ->where('role_user.user_id', $changes['assigned_agent_id'])
                    ->where('users.status', 'active')
                    ->whereIn('roles.name', ['super_admin', 'operator', 'support'])
                    ->exists();
                abort_unless($agentRole, 422, 'ارجاع تیکت فقط به کارشناس فعال پشتیبانی مجاز است.');
            }
            if (($changes['status'] ?? null) === 'resolved') {
                $changes['resolved_at'] = now();
            }
            if ($changes !== []) {
                $changes['updated_at'] = now();
                DB::table('support_tickets')->where('id', $ticketId)->update($changes);
            }
            if (isset($data['message'])) {
                DB::table('support_ticket_messages')->insert([
                    'ticket_id' => $ticketId, 'sender_user_id' => $actor->id,
                    'message' => trim(strip_tags($data['message'])), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit($request, $actor->id, 'admin.ticket.updated', 'support_ticket', $ticketId, $old,
                array_merge($changes, isset($data['message']) ? ['message_added' => true] : []), $data['reason']);

            return DB::table('support_tickets')->where('id', $ticketId)->first();
        });

        return response()->json(['success' => true, 'data' => $ticket]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.audit.view');
        $query = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select(['audit_logs.*', 'users.name as actor_name'])
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';
                $query->where(function (Builder $query) use ($term) {
                    $query->where('audit_logs.action', 'like', $term)->orWhere('audit_logs.auditable_type', 'like', $term)
                        ->orWhere('users.name', 'like', $term);
                });
            })->orderByDesc('audit_logs.id');

        return response()->json(['success' => true, 'data' => $query->paginate($this->pageSize($request))]);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.reports.view');
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $bookings = DB::table('bookings')
            ->when(isset($data['from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $data['from']))
            ->when(isset($data['to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $data['to']));

        return response()->json(['success' => true, 'data' => [
            'bookings_by_status' => (clone $bookings)->select('status', DB::raw('count(*) as total'))->groupBy('status')->get(),
            'booking_count' => (clone $bookings)->count(),
            'booking_revenue' => (clone $bookings)->where('status', 'completed')->sum('final_price'),
            'generated_at' => now()->toIso8601String(),
        ]]);
    }

    public function payments(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'finance.reports.view');
        $query = DB::table('payments')->select([
            'id', 'payment_uuid', 'booking_id', 'user_id', 'amount_minor', 'currency',
            'gateway_name', 'transaction_reference', 'tracking_code', 'status', 'paid_at', 'created_at',
        ])->when($request->filled('q'), function (Builder $query) use ($request) {
            $term = '%'.$request->string('q')->toString().'%';
            $query->where(function (Builder $query) use ($term) {
                $query->where('payment_uuid', 'like', $term)->orWhere('status', 'like', $term)
                    ->orWhere('transaction_reference', 'like', $term)->orWhere('tracking_code', 'like', $term);
            });
        })->orderByDesc('id');

        return response()->json(['success' => true, 'data' => $query->paginate($this->pageSize($request))]);
    }

    public function refundPayment(Request $request, int $paymentId, FinanceController $finance): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'finance.refund');
        $stepUp = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        $this->reauthenticate($actor, $stepUp['password'] ?? null, $request);

        return $finance->refund($request, $paymentId);
    }

    public function reviewSettlement(Request $request, string $settlementUuid, FinanceController $finance): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'finance.payout_approve');
        $stepUp = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'settled'])],
            'payout_reference' => ['required_if:decision,settled', 'nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        $this->reauthenticate($actor, $stepUp['password'] ?? null, $request);

        return $finance->reviewSettlement($request, $settlementUuid);
    }

    public function accessRoles(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.access.manage');
        $roles = DB::table('roles')->orderBy('name')->get();
        foreach ($roles as $role) {
            $role->permissions = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permission_role.role_id', $role->id)->orderBy('permissions.name')->pluck('permissions.name');
        }

        return response()->json(['success' => true, 'data' => ['roles' => $roles,
            'permissions' => DB::table('permissions')->whereIn('name', self::ADMIN_PERMISSIONS)->orderBy('name')->get()]]);
    }

    public function updateAccessRole(Request $request, int $roleId): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.access.manage');
        $data = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(self::ADMIN_PERMISSIONS)],
            'display_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        abort_unless(isset($data['permissions']) || isset($data['display_name']), 422, 'تغییری برای ذخیره ارسال نشده است.');
        $this->reauthenticate($actor, $data['password'] ?? null, $request);
        $role = DB::table('roles')->where('id', $roleId)->first();
        abort_unless($role, 404, 'نقش پیدا نشد.');
        abort_if($role->name === 'super_admin', 422, 'مجوزهای مدیر ارشد قابل ویرایش نیستند.');
        $old = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permission_role.role_id', $roleId)->whereIn('permissions.name', self::ADMIN_PERMISSIONS)
            ->pluck('permissions.name')->all();
        DB::transaction(function () use ($roleId, $data, $request, $actor, $old, $role) {
            if (isset($data['permissions'])) {
                $permissionIds = DB::table('permissions')->whereIn('name', $data['permissions'])->pluck('id');
                DB::table('permission_role')->where('role_id', $roleId)
                    ->whereIn('permission_id', DB::table('permissions')->select('id')->whereIn('name', self::ADMIN_PERMISSIONS))
                    ->delete();
                foreach ($permissionIds as $permissionId) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
            if (isset($data['display_name'])) {
                DB::table('roles')->where('id', $roleId)->update(['display_name' => $data['display_name'], 'updated_at' => now()]);
            }
            $this->audit($request, $actor->id, 'admin.access.role_permissions_changed', 'role', $roleId,
                ['display_name' => $role->display_name, 'permissions' => $old],
                ['display_name' => $data['display_name'] ?? $role->display_name,
                    'permissions' => $data['permissions'] ?? $old], $data['reason']);
        });

        return response()->json(['success' => true]);
    }

    public function createAccessRole(Request $request): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.access.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:roles,name'],
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        $this->reauthenticate($actor, $data['password'] ?? null, $request);
        $roleId = DB::transaction(function () use ($data, $actor, $request) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => $data['name'], 'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, $actor->id, 'admin.access.role_created', 'role', $roleId, null,
                ['name' => $data['name'], 'display_name' => $data['display_name']], $data['reason']);

            return $roleId;
        });

        return response()->json(['success' => true, 'data' => DB::table('roles')->where('id', $roleId)->first()], 201);
    }

    public function securityPolicies(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'admin.access.manage');

        return response()->json(['success' => true, 'data' => DB::table('security_policies')->orderBy('policy_key')->paginate($this->pageSize($request))]);
    }

    public function saveSecurityPolicy(Request $request, string $key): JsonResponse
    {
        $actor = $this->authorizePermission($request, 'admin.access.manage');
        abort_unless($key === 'admin.location_retention_hours', 422, 'سیاست امنیتی پشتیبانی نمی‌شود.');
        $data = $request->validate([
            'value' => ['required', 'array:hours'],
            'value.hours' => ['required', 'integer', 'between:1,8'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        $this->reauthenticate($actor, $data['password'] ?? null, $request);
        DB::transaction(function () use ($request, $actor, $key, $data) {
            $old = DB::table('security_policies')->where('policy_key', $key)->lockForUpdate()->first();
            DB::table('security_policies')->updateOrInsert(['policy_key' => $key], [
                'policy_value' => json_encode($data['value'], JSON_THROW_ON_ERROR),
                'reason' => $data['reason'], 'updated_by_user_id' => $actor->id,
                'updated_at' => now(), 'created_at' => $old?->created_at ?? now(),
            ]);
            $this->audit($request, $actor->id, 'admin.access.policy_changed', 'security_policy', null,
                $old ? (array) $old : null, ['policy_key' => $key, 'value' => $data['value']], $data['reason']);
        });

        return response()->json(['success' => true]);
    }

    private function validatedResource(Request $request, string $resource, bool $creating, ?int $id = null): array
    {
        $rules = match ($resource) {
            'categories' => ['slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:120',
                $creating ? Rule::unique('service_categories', 'slug') : Rule::unique('service_categories', 'slug')->ignore($id)],
                'name_fa' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
                'icon' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'],
                'is_active' => ['sometimes', 'boolean']],
            'services' => ['category_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:service_categories,id'],
                'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                    $creating ? Rule::unique('services', 'slug') : Rule::unique('services', 'slug')->ignore($id)],
                'title_fa' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
                'description' => ['nullable', 'string', 'max:2000'],
                'base_price' => ['sometimes', 'numeric', 'min:0'], 'estimated_duration_minutes' => ['sometimes', 'numeric', 'min:1'],
                'difficulty_level' => ['sometimes', Rule::in(['easy', 'medium', 'hard', 'expert'])],
                'requires_ownership_proof' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean']],
            'areas' => ['city_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
                'zone_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
                'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                    $creating ? Rule::unique('service_areas', 'slug') : Rule::unique('service_areas', 'slug')->ignore($id)],
                'center_lat' => [$creating ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
                'center_lng' => [$creating ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
                'radius_km' => ['sometimes', 'numeric', 'min:0'], 'minimum_trip_km' => ['sometimes', 'numeric', 'min:0'],
                'polygon_geojson' => ['sometimes', 'nullable', 'array'],
                'is_active' => ['sometimes', 'boolean']],
            'pricing' => ['service_area_id' => ['nullable', 'integer', 'exists:service_areas,id'],
                'service_id' => ['nullable', 'integer', 'exists:services,id'],
                'rule_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
                'per_km_rate' => ['sometimes', 'numeric', 'min:0'], 'night_surge_multiplier' => ['sometimes', 'numeric', 'min:0'],
                'emergency_multiplier' => ['sometimes', 'numeric', 'min:0'], 'holiday_multiplier' => ['sometimes', 'numeric', 'min:0'],
                'min_trip_fare' => ['sometimes', 'numeric', 'min:0'], 'is_active' => ['sometimes', 'boolean']],
            'tariffs' => throw ValidationException::withMessages(['resource' => 'تعرفه نسخه‌بندی‌شده فقط از مسیر امن مالی/تعرفه ویرایش می‌شود.']),
            'content' => ['slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $creating ? Rule::unique('content_pages', 'slug') : Rule::unique('content_pages', 'slug')->ignore($id)],
                'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
                'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:50000'],
                'content_type' => [$creating ? 'required' : 'sometimes', Rule::in(['page', 'article'])],
                'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
                'meta_title' => ['sometimes', 'nullable', 'string', 'max:200'],
                'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
                'canonical_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
                'og_image_id' => ['sometimes', 'nullable', 'integer', 'exists:content_media,id'],
                'scheduled_at' => ['sometimes', 'nullable', 'date'],
                'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])]],
            'settings' => ['setting_key' => [$creating ? 'required' : 'sometimes', 'string', 'max:120',
                'regex:/^(?!.*(?:secret|token|password|credential|private|api_key))[a-z][a-z0-9_.-]+$/i',
                $creating ? Rule::unique('app_settings', 'setting_key') : Rule::unique('app_settings', 'setting_key')->ignore($id)],
                'setting_value' => [$creating ? 'required' : 'sometimes', 'array'],
                'category' => ['sometimes', 'string', 'max:40']],
            'tickets', 'templates' => throw ValidationException::withMessages(['resource' => 'این منبع از رابط اختصاصی خود مدیریت می‌شود.']),
            default => throw ValidationException::withMessages(['resource' => 'منبع مدیریتی معتبر نیست.']),
        };
        $data = $request->validate($rules + ['reason' => ['required', 'string', 'min:8', 'max:1000']]);
        unset($data['reason']);
        if ($resource === 'content' && isset($data['canonical_url'])) {
            $canonical = parse_url($data['canonical_url']);
            $application = parse_url((string) config('app.url'));
            abort_unless(
                isset($canonical['scheme'], $canonical['host'], $application['scheme'], $application['host'])
                && strcasecmp($canonical['scheme'], $application['scheme']) === 0
                && strcasecmp($canonical['host'], $application['host']) === 0
                && ($canonical['port'] ?? null) === ($application['port'] ?? null)
                && ! array_key_exists('user', $canonical)
                && ! array_key_exists('pass', $canonical)
                && ! array_key_exists('query', $canonical)
                && ! array_key_exists('fragment', $canonical),
                422, 'نشانی canonical باید به دامنهٔ اصلی همین سایت تعلق داشته باشد.');
        }
        if ($resource === 'areas' && isset($data['polygon_geojson'])) {
            $data['polygon_geojson'] = json_encode($data['polygon_geojson'], JSON_THROW_ON_ERROR);
        }
        if ($resource === 'settings' && isset($data['setting_value'])) {
            $data['setting_value'] = json_encode($data['setting_value'], JSON_THROW_ON_ERROR);
        }

        return $data;
    }

    private function resourceConfig(string $resource): array
    {
        abort_unless(isset(self::RESOURCES[$resource]) && ! in_array($resource, ['tickets', 'templates'], true), 404);

        return self::RESOURCES[$resource];
    }

    private function resourcePermission(string $resource): string
    {
        return match ($resource) {
            'categories', 'services', 'areas', 'pricing', 'tariffs' => 'admin.catalog.manage',
            'content' => 'admin.content.manage',
            'settings' => 'admin.settings.manage',
            'tickets' => 'admin.support.manage',
            'templates' => 'notifications.templates.manage',
            default => throw ValidationException::withMessages(['resource' => 'منبع مدیریتی معتبر نیست.']),
        };
    }

    private function normalizeContentPublication(array $payload, ?object $old = null): array
    {
        $scheduledAt = $payload['scheduled_at'] ?? $old?->scheduled_at;
        $status = $payload['status'] ?? $old?->status ?? 'draft';
        if ($status === 'published' && $scheduledAt && \Illuminate\Support\Carbon::parse($scheduledAt)->isFuture()) {
            $payload['status'] = 'draft';
            $payload['published_at'] = null;
        } elseif ($status === 'published') {
            $payload['published_at'] = $old?->published_at ?? now();
            $payload['scheduled_at'] = null;
        } elseif (array_key_exists('scheduled_at', $payload) && $payload['scheduled_at'] === null) {
            $payload['published_at'] = $status === 'draft' ? null : ($old?->published_at);
        }

        return $payload;
    }

    private function contentPath(string $type, string $slug): string
    {
        return '/'.($type === 'article' ? 'articles' : 'pages').'/'.$slug;
    }

    private function managedPublicPath(string $resource, object $record): ?string
    {
        return match ($resource) {
            'content' => $this->contentPath($record->content_type, $record->slug),
            'services' => '/services/'.$record->slug,
            'areas' => '/areas/'.$record->slug,
            default => null,
        };
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $user = $request->user();
        abort_unless($user, 401, 'احراز هویت الزامی است.');
        $isSuperAdmin = $this->isSuperAdmin($user->id);
        $allowed = $isSuperAdmin || DB::table('role_user')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('role_user.user_id', $user->id)->where('permissions.name', $permission)->exists();
        abort_unless(DB::table('users')->where('id', $user->id)->where('status', 'active')->exists(), 403,
            'حساب کاربری فعال نیست.');
        abort_unless($allowed, 403, 'مجوز انجام این عملیات را ندارید.');

        return $user;
    }

    private function reauthenticate(object $actor, ?string $password, Request $request): void
    {
        if ($password !== null) {
            abort_unless($actor->password && Hash::check($password, $actor->password), 422,
                'تأیید مجدد رمز عبور ناموفق بود.');

            return;
        }

        $tokenCreatedAt = $request->user()?->currentAccessToken()?->created_at;
        abort_unless($tokenCreatedAt && now()->subMinutes(5)->lte($tokenCreatedAt), 422,
            'رمز عبور معتبر یا ورود مجدد با رمز یک‌بارمصرف در پنج دقیقهٔ اخیر لازم است.');
    }

    private function isSuperAdmin(int $userId): bool
    {
        return DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $userId)->where('roles.name', 'super_admin')->exists();
    }

    private function assertSafeSettingsPayload(array $payload): void
    {
        $value = json_decode($payload['setting_value'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
        $inspect = function (mixed $item) use (&$inspect): bool {
            if (! is_array($item)) {
                return is_string($item)
                    && preg_match('/(?:api[_-]?key|access[_-]?token|password|secret|authorization)\s*[:=]|Bearer\s+[A-Za-z0-9._-]+/i', $item) === 1;
            }
            foreach ($item as $key => $nested) {
                if (is_string($key) && preg_match('/(?:secret|token|password|credential|private|api[_-]?key|authorization)/i', $key) === 1) {
                    return true;
                }
                if ($inspect($nested)) {
                    return true;
                }
            }

            return false;
        };
        abort_unless(! $inspect($value), 422, 'اطلاعات محرمانه باید در مخزن اتصال امن نگهداری شود، نه تنظیمات عمومی.');
    }

    private function roleNames(int $userId): array
    {
        return DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $userId)->orderBy('roles.name')->pluck('roles.name')->all();
    }

    private function audit(Request $request, int $actorId, string $action, string $type, ?int $id,
        ?array $old, ?array $new, ?string $reason): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $actorId, 'action' => $action, 'auditable_type' => $type,
            'auditable_id' => $id, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            'old_values' => $old ? json_encode($old, JSON_THROW_ON_ERROR) : null,
            'new_values' => $new ? json_encode($new, JSON_THROW_ON_ERROR) : null,
            'reason' => $reason, 'request_id' => $request->header('X-Request-Id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pageSize(Request $request): int
    {
        return min(max((int) $request->query('per_page', 20), 1), 100);
    }

    private function invalidatePublicCatalogForResource(string $resource): void
    {
        if (in_array($resource, ['categories', 'services'], true)) {
            Cache::forget('public:service-catalog:v1');
        }
    }
}
