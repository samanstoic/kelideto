<?php

namespace App\Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TariffPricingService
{
    public function quote(int $serviceId, float $latitude, float $longitude, ?string $scheduledAt = null): array
    {
        $service = DB::table('services')
            ->join('service_categories', 'services.category_id', '=', 'service_categories.id')
            ->where('services.id', $serviceId)
            ->where('services.is_active', true)
            ->where('service_categories.is_active', true)
            ->select('services.*')
            ->first();

        if (!$service) {
            throw ValidationException::withMessages([
                'service_id' => 'این خدمت در حال حاضر فعال نیست.',
            ]);
        }

        $scheduled = $scheduledAt
            ? CarbonImmutable::parse($scheduledAt)->setTimezone(config('app.timezone'))
            : CarbonImmutable::now(config('app.timezone'));
        $areas = DB::table('service_areas')->where('is_active', true)->get();
        $coveredAreas = [];

        foreach ($areas as $area) {
            $distance = $this->distanceKm(
                $latitude,
                $longitude,
                (float) $area->center_lat,
                (float) $area->center_lng
            );

            if ($distance <= (float) $area->radius_km) {
                $area->distance_from_center_km = $distance;
                $coveredAreas[] = $area;
            }
        }

        usort(
            $coveredAreas,
            static fn (object $left, object $right): int => $left->distance_from_center_km <=> $right->distance_from_center_km
        );

        $area = $coveredAreas[0] ?? null;
        if ($area) {
            $tariff = DB::table('service_tariffs')
                ->where('service_id', $serviceId)
                ->where('service_area_id', $area->id)
                ->where('effective_from', '<=', $scheduled)
                ->where(function ($query) use ($scheduled) {
                    $query->whereNull('effective_until')->orWhere('effective_until', '>', $scheduled);
                })
                ->orderByDesc('version')
                ->first();

            if ($tariff) {
                return $this->calculate($service, $area, $tariff, $scheduled);
            }
        }

        throw ValidationException::withMessages([
            'location' => 'برای این محدوده و زمان، تعرفهٔ فعال و پوشش اعزام تعریف نشده است.',
        ]);
    }

    public static function distanceKm(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2 +
            cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;
        $a = min(1, max(0, $a));

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function calculate(object $service, object $area, object $tariff, CarbonImmutable $scheduled): array
    {
        if ((float) $tariff->minimum_trip_km > (float) $area->radius_km) {
            throw ValidationException::withMessages([
                'pricing' => 'حداقل مسافت اعزام از شعاع پوشش منطقه بیشتر است.',
            ]);
        }

        $isNight = $this->isInTimeWindow(
            $scheduled,
            (string) $tariff->night_start,
            (string) $tariff->night_end
        );
        $minimumTripKm = max(
            (float) $area->minimum_trip_km,
            (float) $tariff->minimum_trip_km
        );
        $distanceKm = max((float) $area->distance_from_center_km, $minimumTripKm);
        $baseFare = (float) $tariff->base_fare;
        $dispatchFee = (float) $tariff->dispatch_fee;
        $distanceFare = $distanceKm * (float) $tariff->per_km_rate;
        $timeFare = (float) $service->estimated_duration_minutes * (float) $tariff->per_minute_rate;
        $regularSubtotal = $baseFare + $dispatchFee + $distanceFare + $timeFare;
        $nightMultiplier = $isNight ? (float) $tariff->night_multiplier : 1.0;
        $subtotal = max(
            (float) $tariff->minimum_total,
            round($regularSubtotal * $nightMultiplier, 2)
        );
        $nightSurcharge = max(0, round($subtotal - $regularSubtotal, 2));
        $margin = min(100, max(0, (float) $tariff->estimate_margin_percent)) / 100;
        $snapshot = [
            'service_id' => (int) $service->id,
            'service_slug' => $service->slug,
            'service_name' => $service->title_fa,
            'service_duration_minutes' => (float) $service->estimated_duration_minutes,
            'service_area_id' => (int) $area->id,
            'service_area_name' => $area->city_name . '، ' . $area->zone_name,
            'distance_from_area_center_km' => round((float) $area->distance_from_center_km, 2),
            'minimum_trip_km' => $minimumTripKm,
            'service_tariff_id' => (int) $tariff->id,
            'tariff_version' => (int) $tariff->version,
            'effective_at' => $scheduled->toDateTimeString(),
            'rates' => [
                'base_fare' => $baseFare,
                'dispatch_fee' => $dispatchFee,
                'per_km_rate' => (float) $tariff->per_km_rate,
                'per_minute_rate' => (float) $tariff->per_minute_rate,
                'minimum_total' => (float) $tariff->minimum_total,
                'night_start' => $tariff->night_start,
                'night_end' => $tariff->night_end,
                'night_multiplier' => (float) $tariff->night_multiplier,
            ],
            'components' => [
                'base_fare' => round($baseFare, 2),
                'dispatch_fee' => round($dispatchFee, 2),
                'distance_fare' => round($distanceFare, 2),
                'time_fare' => round($timeFare, 2),
                'night_multiplier' => $nightMultiplier,
                'night_surcharge' => $nightSurcharge,
                'minimum_total_applied' => $subtotal === (float) $tariff->minimum_total,
            ],
            'estimated_subtotal' => $subtotal,
            'estimate_margin_percent' => (float) $tariff->estimate_margin_percent,
        ];

        return [
            'service' => $service,
            'area' => $area,
            'tariff' => $tariff,
            'scheduled_at' => $scheduled,
            'distance_km' => round($distanceKm, 2),
            'base_fare' => round($baseFare, 2),
            'dispatch_fee' => round($dispatchFee, 2),
            'distance_fare' => round($distanceFare, 2),
            'time_fare' => round($timeFare, 2),
            'night_surcharge' => $nightSurcharge,
            'subtotal' => $subtotal,
            'minimum_total' => round($subtotal * (1 - $margin), 2),
            'maximum_total' => round($subtotal * (1 + $margin), 2),
            'snapshot' => $snapshot,
        ];
    }

    private function isInTimeWindow(CarbonImmutable $time, string $start, string $end): bool
    {
        $currentTime = $time->format('H:i:s');
        if ($start <= $end) {
            return $currentTime >= $start && $currentTime < $end;
        }

        return $currentTime >= $start || $currentTime < $end;
    }
}
