<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineDraftSyncController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $hasPermission = DB::table('permission_role')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->where('role_user.user_id', $user->id)
            ->where('roles.name', 'customer')
            ->where('permissions.name', 'bookings.create')
            ->exists();

        if (!$hasPermission || $user->status !== 'active') {
            return response()->json([
                'success' => false,
                'error' => 'FORBIDDEN_BOOKING_SYNC',
                'message' => 'مجوز همگام‌سازی درخواست برای این حساب فعال نیست.',
            ], 403);
        }

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'service_id' => ['required', 'string', 'max:64'],
            'service_name' => ['required', 'string', 'max:120'],
            'lat' => ['required', 'numeric', 'between:-90,90', 'decimal:0,7'],
            'lng' => ['required', 'numeric', 'between:-180,180', 'decimal:0,7'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'created_at' => ['required', 'date'],
        ]);

        if ($request->header('X-Idempotency-Key') !== $validated['idempotency_key']) {
            return response()->json([
                'success' => false,
                'error' => 'IDEMPOTENCY_KEY_MISMATCH',
                'message' => 'شناسهٔ یکتای سربرگ و بدنهٔ درخواست یکسان نیست.',
            ], 422);
        }

        $payload = $validated;
        $clientRecordId = $validated['idempotency_key'];

        try {
            $result = DB::transaction(function () use ($user, $clientRecordId, $payload): array {
                $existing = DB::table('offline_sync_records')
                    ->where('client_record_uuid', $clientRecordId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ((int) $existing->user_id !== (int) $user->id || json_decode($existing->payload, true) != $payload) {
                        return ['conflict' => true, 'created' => false];
                    }
                    return ['conflict' => false, 'created' => false];
                }

                DB::table('offline_sync_records')->insert([
                    'client_record_uuid' => $clientRecordId,
                    'user_id' => $user->id,
                    'entity_type' => 'booking',
                    'action' => 'create',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'sync_status' => 'synced',
                    'client_timestamp' => $payload['created_at'],
                    'server_synced_at' => now(),
                    'expires_at' => now()->addDays(7),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['conflict' => false, 'created' => true];
            });
        } catch (QueryException $exception) {
            $existing = DB::table('offline_sync_records')->where('client_record_uuid', $clientRecordId)->first();
            if (!$existing) {
                throw $exception;
            }
            $result = [
                'conflict' => (int) $existing->user_id !== (int) $user->id ||
                    json_decode($existing->payload, true) != $payload,
                'created' => false,
            ];
        }

        if ($result['conflict']) {
            return response()->json([
                'success' => false,
                'error' => 'IDEMPOTENCY_KEY_REUSED',
                'message' => 'این شناسهٔ یکتا قبلاً برای محتوای متفاوتی استفاده شده است.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'already_synced' => !$result['created'],
            'message' => $result['created'] ? 'پیش‌نویس با موفقیت همگام‌سازی شد.' : 'این پیش‌نویس قبلاً همگام‌سازی شده است.',
        ], $result['created'] ? 201 : 200);
    }
}
