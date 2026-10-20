<?php

namespace App\Modules\Booking\Services;

use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Identity\Services\LocationAccessWindow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DispatchService
{
    private const OFFER_WINDOW_SECONDS = 90;

    private const TELEMETRY_MAX_AGE_MINUTES = 15;

    public function __construct(
        private readonly BookingLifecycleService $lifecycle,
        private readonly NotificationService $notifications,
        private readonly LocationAccessWindow $locationAccessWindow,
    ) {}

    public function issueNextOffer(
        int $bookingId,
        ?int $actorId,
        ?int $windowSeconds = null,
        ?array $technicianIds = null,
    ): array {
        $windowSeconds ??= (int) config('keypro.dispatch.response_window_seconds', self::OFFER_WINDOW_SECONDS);

        return DB::transaction(function () use ($bookingId, $actorId, $windowSeconds, $technicianIds): array {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            if (! $booking) {
                return ['error' => 'BOOKING_NOT_FOUND'];
            }
            if (! in_array($booking->status, ['draft', 'broadcasting'], true) || $booking->technician_id !== null) {
                return ['error' => 'BOOKING_NOT_DISPATCHABLE'];
            }

            $areaId = $booking->price_estimate_id
                ? DB::table('price_estimates')->where('id', $booking->price_estimate_id)->value('service_area_id')
                : null;
            if (! $areaId) {
                return ['error' => 'SERVICE_AREA_UNAVAILABLE'];
            }

            $now = now();
            $expiredOffers = DB::table('dispatch_offers')
                ->where('booking_id', $bookingId)
                ->where('status', 'pending')
                ->where('expires_at', '<=', $now)
                ->lockForUpdate()
                ->get(['id', 'technician_id']);
            foreach ($expiredOffers as $expiredOffer) {
                DB::table('dispatch_offers')->where('id', $expiredOffer->id)->update([
                    'status' => 'expired',
                    'updated_at' => $now,
                ]);
                $this->recordEvent(
                    $bookingId,
                    (int) $expiredOffer->technician_id,
                    null,
                    'offer.expired',
                    null,
                    ['offer_id' => (int) $expiredOffer->id],
                );
            }

            $activeOffer = DB::table('dispatch_offers')
                ->where('booking_id', $bookingId)
                ->where('status', 'pending')
                ->where('expires_at', '>', $now)
                ->first();
            if ($activeOffer) {
                return ['error' => 'DISPATCH_OFFER_ALREADY_PENDING'];
            }

            $area = DB::table('service_areas')
                ->where('id', $areaId)
                ->where('is_active', true)
                ->first();
            if (! $area) {
                return ['error' => 'SERVICE_AREA_UNAVAILABLE'];
            }

            $candidates = $this->eligibleCandidates($booking, $area, $technicianIds);
            if ($technicianIds !== null && count($candidates) !== count(array_unique($technicianIds))) {
                return ['error' => 'TECHNICIAN_NOT_ELIGIBLE'];
            }
            if ($candidates === []) {
                $this->recordEvent($bookingId, null, $actorId, 'dispatch.no_eligible_technicians');

                return ['error' => 'NO_ELIGIBLE_TECHNICIANS'];
            }

            if ($booking->status === 'draft') {
                $transition = $this->lifecycle->transition(
                    $bookingId,
                    'broadcasting',
                    $actorId,
                    'جست‌وجوی متخصصان واجد شرایط آغاز شد.',
                );
                if (isset($transition['error'])) {
                    return $transition;
                }
            }

            $technician = $candidates[0];
            $round = (int) DB::table('dispatch_offers')
                ->where('booking_id', $bookingId)
                ->where('technician_id', $technician->id)
                ->max('round_number') + 1;
            $offerId = DB::table('dispatch_offers')->insertGetId([
                'booking_id' => $bookingId,
                'technician_id' => $technician->id,
                'round_number' => $round,
                'distance_km' => round($technician->dispatch_distance_km, 2),
                'offered_earnings' => max(
                    0,
                    round((float) $booking->estimated_price * (1 - (float) $booking->platform_commission_rate / 100), 2),
                ),
                'status' => 'pending',
                'expires_at' => $now->copy()->addSeconds($windowSeconds),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordEvent(
                $bookingId,
                (int) $technician->id,
                $actorId,
                'offer.issued',
                null,
                [
                    'offer_id' => $offerId,
                    'round_number' => $round,
                    'distance_km' => round($technician->dispatch_distance_km, 2),
                    'distance_method' => $technician->distance_method,
                    'estimated_travel_time_minutes' => null,
                    'travel_time_status' => 'unavailable',
                    'expires_at' => $now->copy()->addSeconds($windowSeconds)->toIso8601String(),
                ],
            );
            $this->notifications->notifyTechnicianOffer($offerId);

            return [
                'offer_id' => $offerId,
                'technician_id' => (int) $technician->id,
                'expires_at' => $now->copy()->addSeconds($windowSeconds)->toIso8601String(),
                'distance_km' => round($technician->dispatch_distance_km, 2),
                'distance_method' => $technician->distance_method,
                'estimated_travel_time_minutes' => null,
                'travel_time_status' => 'unavailable',
            ];
        }, 3);
    }

    public function respondToOffer(int $offerId, int $technicianId, int $userId, string $decision): array
    {
        try {
            $result = DB::transaction(function () use ($offerId, $technicianId, $userId, $decision): array {
                $offer = DB::table('dispatch_offers')->where('id', $offerId)->first();
                if (! $offer || (int) $offer->technician_id !== $technicianId) {
                    return ['error' => 'OFFER_NOT_FOUND'];
                }
                $booking = DB::table('bookings')->where('id', $offer->booking_id)->lockForUpdate()->first();
                if (! $booking || $booking->status !== 'broadcasting' || $booking->technician_id !== null) {
                    return ['error' => 'BOOKING_ALREADY_ASSIGNED'];
                }
                $offer = DB::table('dispatch_offers')->where('id', $offerId)->lockForUpdate()->first();
                if (! $offer || (int) $offer->technician_id !== $technicianId) {
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
                    $this->recordEvent(
                        (int) $booking->id,
                        $technicianId,
                        $userId,
                        'offer.expired',
                        null,
                        ['offer_id' => $offerId],
                    );

                    return ['status' => 'expired', 'booking_id' => (int) $booking->id];
                }

                if ($decision === 'rejected') {
                    DB::table('dispatch_offers')->where('id', $offerId)->update([
                        'status' => 'rejected',
                        'updated_at' => now(),
                    ]);
                    $this->recordEvent(
                        (int) $booking->id,
                        $technicianId,
                        $userId,
                        'offer.rejected',
                        null,
                        ['offer_id' => $offerId],
                    );

                    return ['status' => 'rejected', 'booking_id' => (int) $booking->id];
                }

                $profile = DB::table('technician_profiles')
                    ->where('id', $technicianId)
                    ->lockForUpdate()
                    ->first();
                if (! $profile || $profile->status !== 'verified') {
                    return ['error' => 'TECHNICIAN_NOT_VERIFIED'];
                }
                $activeTechnician = DB::table('users')
                    ->join('role_user', 'users.id', '=', 'role_user.user_id')
                    ->join('roles', 'role_user.role_id', '=', 'roles.id')
                    ->where('users.id', $userId)
                    ->where('users.status', 'active')
                    ->where('roles.name', 'technician')
                    ->exists();
                if (! $activeTechnician || ! $profile->is_online || ! $profile->is_available) {
                    return ['error' => 'TECHNICIAN_NOT_AVAILABLE'];
                }
                $areaId = $booking->price_estimate_id
                    ? DB::table('price_estimates')->where('id', $booking->price_estimate_id)->value('service_area_id')
                    : null;
                $area = $areaId
                    ? DB::table('service_areas')->where('id', $areaId)->where('is_active', true)->first()
                    : null;
                if (! $area || ! $this->isEligible($profile, $booking, $area)) {
                    return ['error' => 'TECHNICIAN_NOT_ELIGIBLE'];
                }

                $this->assignLocked($booking, $profile, $offerId, $userId, false, null);

                return ['status' => 'accepted', 'booking_id' => (int) $booking->id];
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return ['error' => 'BOOKING_ALREADY_ASSIGNED'];
            }
            throw $exception;
        }

        if (in_array($result['status'] ?? null, ['rejected', 'expired'], true)) {
            $result['next_offer'] = $this->issueNextOffer(
                (int) $result['booking_id'],
                $userId,
            );
            unset($result['booking_id']);
        }

        return $result;
    }

    public function assignManually(int $bookingId, int $technicianId, int $actorId, string $reason): array
    {
        try {
            return DB::transaction(function () use ($bookingId, $technicianId, $actorId, $reason): array {
                $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
                if (! $booking) {
                    return ['error' => 'BOOKING_NOT_FOUND'];
                }
                if (! in_array($booking->status, ['draft', 'broadcasting'], true) || $booking->technician_id !== null) {
                    return ['error' => 'BOOKING_NOT_DISPATCHABLE'];
                }
                $areaId = $booking->price_estimate_id
                    ? DB::table('price_estimates')->where('id', $booking->price_estimate_id)->value('service_area_id')
                    : null;
                $area = $areaId
                    ? DB::table('service_areas')->where('id', $areaId)->where('is_active', true)->first()
                    : null;
                if (! $area) {
                    return ['error' => 'SERVICE_AREA_UNAVAILABLE'];
                }
                $profile = DB::table('technician_profiles')
                    ->where('id', $technicianId)
                    ->lockForUpdate()
                    ->first();
                if (! $profile || ! $this->isEligible($profile, $booking, $area)) {
                    return ['error' => 'TECHNICIAN_NOT_ELIGIBLE'];
                }

                if ($booking->status === 'draft') {
                    $transition = $this->lifecycle->transition(
                        $bookingId,
                        'broadcasting',
                        $actorId,
                        'تخصیص دستی با دلیل ثبت‌شده توسط اپراتور.',
                    );
                    if (isset($transition['error'])) {
                        return $transition;
                    }
                }
                $this->assignLocked($booking, $profile, null, $actorId, true, $reason);

                return ['status' => 'assigned', 'technician_id' => $technicianId];
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return ['error' => 'BOOKING_ALREADY_ASSIGNED'];
            }
            throw $exception;
        }
    }

    public function expireDueOffers(): int
    {
        $bookingIds = DB::table('dispatch_offers')
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->distinct()
            ->pluck('booking_id');
        $expiredCount = 0;

        foreach ($bookingIds as $bookingId) {
            $expired = DB::transaction(function () use ($bookingId): int {
                $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
                if (! $booking || $booking->status !== 'broadcasting' || $booking->technician_id !== null) {
                    return 0;
                }
                $offers = DB::table('dispatch_offers')
                    ->where('booking_id', $bookingId)
                    ->where('status', 'pending')
                    ->where('expires_at', '<=', now())
                    ->lockForUpdate()
                    ->get(['id', 'technician_id']);
                foreach ($offers as $offer) {
                    DB::table('dispatch_offers')->where('id', $offer->id)->update([
                        'status' => 'expired',
                        'updated_at' => now(),
                    ]);
                    $this->recordEvent(
                        (int) $bookingId,
                        (int) $offer->technician_id,
                        null,
                        'offer.expired',
                        null,
                        ['offer_id' => (int) $offer->id],
                    );
                }

                return $offers->count();
            }, 3);

            if ($expired > 0) {
                $expiredCount += $expired;
                $this->issueNextOffer((int) $bookingId, null);
            }
        }

        return $expiredCount;
    }

    public function events(int $bookingId): array
    {
        return DB::table('dispatch_events')
            ->where('booking_id', $bookingId)
            ->orderBy('id')
            ->get([
                'event_type',
                'technician_id',
                'actor_user_id',
                'reason',
                'metadata',
                'created_at',
            ])
            ->all();
    }

    private function eligibleCandidates(object $booking, object $area, ?array $technicianIds): array
    {
        $previouslyOffered = DB::table('dispatch_offers')
            ->where('booking_id', $booking->id)
            ->pluck('technician_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $at = $booking->scheduled_at ? Carbon::parse($booking->scheduled_at) : now();
        $query = DB::table('technician_profiles')
            ->join('users', 'technician_profiles.user_id', '=', 'users.id')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->join('technician_services', 'technician_profiles.id', '=', 'technician_services.technician_id')
            ->join('services', 'technician_services.service_id', '=', 'services.id')
            ->join('technician_service_areas', 'technician_profiles.id', '=', 'technician_service_areas.technician_id')
            ->join('service_areas', 'technician_service_areas.service_area_id', '=', 'service_areas.id')
            ->where('technician_profiles.status', 'verified')
            ->where('technician_profiles.is_online', true)
            ->where('technician_profiles.is_available', true)
            ->where('technician_profiles.last_telemetry_at', '>=', now()->subMinutes(self::TELEMETRY_MAX_AGE_MINUTES))
            ->whereNotNull('technician_profiles.current_lat')
            ->whereNotNull('technician_profiles.current_lng')
            ->where('users.status', 'active')
            ->where('roles.name', 'technician')
            ->where('technician_services.service_id', $booking->service_id)
            ->where('technician_service_areas.service_area_id', $area->id)
            ->where('services.is_active', true)
            ->where('service_areas.is_active', true)
            ->where('service_areas.id', $area->id)
            ->select('technician_profiles.*');
        if ($technicianIds !== null) {
            $query->whereIn('technician_profiles.id', $technicianIds);
        }
        if ($previouslyOffered !== []) {
            $query->whereNotIn('technician_profiles.id', $previouslyOffered);
        }
        if (DB::getDriverName() === 'pgsql') {
            $query->where(function ($areaQuery) use ($booking) {
                $areaQuery->where(function ($polygonQuery) use ($booking) {
                    $polygonQuery->whereNotNull('service_areas.polygon_geojson')
                        ->whereRaw(
                            'ST_Covers(ST_SetSRID(ST_GeomFromGeoJSON(service_areas.polygon_geojson::text), 4326), ST_SetSRID(ST_MakePoint(?, ?), 4326))',
                            [(float) $booking->longitude, (float) $booking->latitude],
                        );
                })->orWhere(function ($radiusQuery) use ($booking) {
                    $radiusQuery->whereNull('service_areas.polygon_geojson')
                        ->whereRaw(
                            'ST_DWithin(ST_SetSRID(ST_MakePoint(service_areas.center_lng, service_areas.center_lat), 4326)::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, service_areas.radius_km * 1000)',
                            [(float) $booking->longitude, (float) $booking->latitude],
                        );
                });
            })->selectRaw(
                'ST_Distance(ST_SetSRID(ST_MakePoint(technician_profiles.current_lng, technician_profiles.current_lat), 4326)::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000.0 as dispatch_distance_km',
                [(float) $booking->longitude, (float) $booking->latitude],
            );
        }

        $time = $at->format('H:i:s');
        $day = $at->dayOfWeek;
        $profiles = $query->get();
        $candidates = [];
        if (DB::getDriverName() !== 'pgsql' && ! $this->withinServiceArea($area, $booking)) {
            return [];
        }
        foreach ($profiles as $profile) {
            if (! DB::table('technician_working_hours')
                ->where('technician_id', $profile->id)
                ->where('day_of_week', $day)
                ->where('is_active', true)
                ->where('starts_at', '<=', $time)
                ->where('ends_at', '>', $time)
                ->exists()) {
                continue;
            }

            $capacity = DB::table('technician_working_hours')
                ->where('technician_id', $profile->id)
                ->where('day_of_week', $day)
                ->where('is_active', true)
                ->where('starts_at', '<=', $time)
                ->where('ends_at', '>', $time)
                ->max('capacity');
            if ($capacity === null || $this->activeAssignmentCount((int) $profile->id) >= (int) $capacity) {
                continue;
            }
            if (DB::getDriverName() !== 'pgsql') {
                $profile->dispatch_distance_km = TariffPricingService::distanceKm(
                    (float) $profile->current_lat,
                    (float) $profile->current_lng,
                    (float) $booking->latitude,
                    (float) $booking->longitude,
                );
            }
            $profile->distance_method = DB::getDriverName() === 'pgsql' ? 'postgis_geography' : 'haversine_geodesic';
            $candidates[] = $profile;
        }

        usort($candidates, static fn (object $a, object $b): int => [$a->dispatch_distance_km, $a->id] <=> [$b->dispatch_distance_km, $b->id]
        );

        return $candidates;
    }

    private function isEligible(object $profile, object $booking, object $area): bool
    {
        if ($profile->status !== 'verified' || ! $profile->is_online || ! $profile->is_available) {
            return false;
        }

        $userIsActive = DB::table('users')->where('id', $profile->user_id)->where('status', 'active')->exists();
        $hasTechnicianRole = DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $profile->user_id)
            ->where('roles.name', 'technician')
            ->exists();
        $serviceActive = DB::table('technician_services')
            ->join('services', 'technician_services.service_id', '=', 'services.id')
            ->where('technician_services.technician_id', $profile->id)
            ->where('technician_services.service_id', $booking->service_id)
            ->where('services.is_active', true)
            ->exists();
        $areaAssigned = DB::table('technician_service_areas')
            ->where('technician_id', $profile->id)
            ->where('service_area_id', $area->id)
            ->exists();
        if (! $userIsActive || ! $hasTechnicianRole || ! $serviceActive || ! $areaAssigned ||
            $profile->current_lat === null || $profile->current_lng === null ||
            ! $profile->last_telemetry_at ||
            Carbon::parse($profile->last_telemetry_at)->lt(now()->subMinutes(self::TELEMETRY_MAX_AGE_MINUTES)) ||
            ! $this->withinServiceArea($area, $booking)) {
            return false;
        }

        $at = $booking->scheduled_at ? Carbon::parse($booking->scheduled_at) : now();
        $time = $at->format('H:i:s');
        $capacity = DB::table('technician_working_hours')
            ->where('technician_id', $profile->id)
            ->where('day_of_week', $at->dayOfWeek)
            ->where('is_active', true)
            ->where('starts_at', '<=', $time)
            ->where('ends_at', '>', $time)
            ->max('capacity');

        return $capacity !== null &&
            $this->activeAssignmentCount((int) $profile->id) < (int) $capacity;
    }

    private function withinServiceArea(object $area, object $booking): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::table('service_areas')
                ->where('id', $area->id)
                ->where(function ($query) use ($booking) {
                    $query->where(function ($polygonQuery) use ($booking) {
                        $polygonQuery->whereNotNull('polygon_geojson')
                            ->whereRaw(
                                'ST_Covers(ST_SetSRID(ST_GeomFromGeoJSON(polygon_geojson::text), 4326), ST_SetSRID(ST_MakePoint(?, ?), 4326))',
                                [(float) $booking->longitude, (float) $booking->latitude],
                            );
                    })->orWhere(function ($radiusQuery) use ($booking) {
                        $radiusQuery->whereNull('polygon_geojson')
                            ->whereRaw(
                                'ST_DWithin(ST_SetSRID(ST_MakePoint(center_lng, center_lat), 4326)::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, radius_km * 1000)',
                                [(float) $booking->longitude, (float) $booking->latitude],
                            );
                    });
                })
                ->exists();
        }

        if ($area->polygon_geojson) {
            $polygon = is_string($area->polygon_geojson)
                ? json_decode($area->polygon_geojson, true)
                : (array) $area->polygon_geojson;
            if (! is_array($polygon) || json_last_error() !== JSON_ERROR_NONE && is_string($area->polygon_geojson)) {
                return false;
            }

            return $this->pointInGeoJson((float) $booking->longitude, (float) $booking->latitude, $polygon);
        }

        return TariffPricingService::distanceKm(
            (float) $area->center_lat,
            (float) $area->center_lng,
            (float) $booking->latitude,
            (float) $booking->longitude,
        ) <= (float) $area->radius_km;
    }

    private function pointInGeoJson(float $longitude, float $latitude, array $geoJson): bool
    {
        $type = $geoJson['type'] ?? null;
        $coordinates = $geoJson['coordinates'] ?? null;
        if (! is_array($coordinates)) {
            return false;
        }
        $polygons = match ($type) {
            'Polygon' => [$coordinates],
            'MultiPolygon' => $coordinates,
            default => [],
        };

        foreach ($polygons as $polygon) {
            $outerRing = $polygon[0] ?? null;
            if (! is_array($outerRing) || ! $this->pointInRing($longitude, $latitude, $outerRing)) {
                continue;
            }
            foreach (array_slice($polygon, 1) as $hole) {
                if (is_array($hole) && $this->pointInRing($longitude, $latitude, $hole)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    private function pointInRing(float $longitude, float $latitude, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xI = (float) ($ring[$i][0] ?? 0);
            $yI = (float) ($ring[$i][1] ?? 0);
            $xJ = (float) ($ring[$j][0] ?? 0);
            $yJ = (float) ($ring[$j][1] ?? 0);
            if (($yI > $latitude) !== ($yJ > $latitude) &&
                $longitude < ($xJ - $xI) * ($latitude - $yI) / (($yJ - $yI) ?: PHP_FLOAT_EPSILON) + $xI) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function assignLocked(
        object $booking,
        object $profile,
        ?int $offerId,
        int $actorId,
        bool $manual,
        ?string $reason,
    ): void {
        $now = now();
        DB::table('assignments')->insert([
            'booking_id' => $booking->id,
            'technician_id' => $profile->id,
            'offer_id' => $offerId,
            'status' => 'active',
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('bookings')->where('id', $booking->id)->update([
            'technician_id' => $profile->id,
            'assigned_at' => $now,
            'location_access_expires_at' => $now->copy()->addHours($this->locationAccessWindow->hours()),
            'updated_at' => $now,
        ]);
        $pendingOffers = DB::table('dispatch_offers')
            ->where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->get(['id', 'technician_id']);
        DB::table('dispatch_offers')->where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->update(['status' => 'expired', 'updated_at' => $now]);
        foreach ($pendingOffers as $pendingOffer) {
            if ($offerId !== null && (int) $pendingOffer->id === $offerId) {
                continue;
            }
            $this->recordEvent(
                (int) $booking->id,
                (int) $pendingOffer->technician_id,
                $actorId,
                'offer.closed_by_assignment',
                $manual ? $reason : null,
                ['offer_id' => (int) $pendingOffer->id],
            );
        }
        if ($offerId !== null) {
            DB::table('dispatch_offers')->where('id', $offerId)->update([
                'status' => 'accepted',
                'updated_at' => $now,
            ]);
            $this->recordEvent(
                (int) $booking->id,
                (int) $profile->id,
                $actorId,
                'offer.accepted',
                null,
                ['offer_id' => $offerId],
            );
        }
        DB::table('technician_profiles')->where('id', $profile->id)->update([
            'is_available' => false,
            'updated_at' => $now,
        ]);
        $transition = $this->lifecycle->transition(
            (int) $booking->id,
            'assigned',
            $actorId,
            $manual ? 'تخصیص دستی: '.$reason : 'متخصص پیشنهاد مأموریت را پذیرفت.',
        );
        if (isset($transition['error'])) {
            throw new \RuntimeException('Booking assignment lost its dispatchable state.');
        }
        $this->recordEvent(
            (int) $booking->id,
            (int) $profile->id,
            $actorId,
            $manual ? 'assignment.manual' : 'assignment.accepted',
            $reason,
            ['offer_id' => $offerId],
        );
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

    private function recordEvent(
        int $bookingId,
        ?int $technicianId,
        ?int $actorId,
        string $eventType,
        ?string $reason = null,
        ?array $metadata = null,
    ): void {
        DB::table('dispatch_events')->insert([
            'booking_id' => $bookingId,
            'technician_id' => $technicianId,
            'actor_user_id' => $actorId,
            'event_type' => $eventType,
            'reason' => $reason,
            'metadata' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return match (DB::getDriverName()) {
            'mysql', 'mariadb' => $driverCode === 1062,
            'pgsql' => $sqlState === '23505',
            'sqlite' => $driverCode === 19 && str_contains($message, 'unique constraint'),
            default => $sqlState === '23505' ||
                ($sqlState === '23000' && str_contains($message, 'unique')),
        };
    }
}
