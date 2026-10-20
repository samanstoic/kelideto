<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ServiceCatalogController extends Controller
{
    private const PUBLIC_CATALOG_CACHE_KEY = 'public:service-catalog:v1';

    public function index(): JsonResponse
    {
        $categories = Cache::remember(self::PUBLIC_CATALOG_CACHE_KEY, now()->addMinutes(5), function () {
            return DB::table('service_categories')
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get()
                ->map(function (object $category): object {
                    $category->services = DB::table('services')
                        ->where('category_id', $category->id)
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->get([
                            'id',
                            'title_fa',
                            'slug',
                            'description',
                            'estimated_duration_minutes',
                            'requires_ownership_proof',
                        ]);

                    return $category;
                });
        });

        return response()->json(['data' => $categories]);
    }

    public function manageCategories(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        $categories = DB::table('service_categories')->orderBy('display_order')->orderBy('id')->get();

        return response()->json(['data' => $categories]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'name_fa' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', 'unique:service_categories,slug'],
            'icon' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $now = now();
        $id = DB::table('service_categories')->insertGetId($validated + [
            'display_order' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->invalidatePublicCatalog();
        $this->writeAudit($request, 'catalog.category.created', 'service_category', $id, null,
            (array) DB::table('service_categories')->find($id));

        return response()->json(['data' => DB::table('service_categories')->find($id)], 201);
    }

    public function updateCategory(Request $request, int $categoryId): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'name_fa' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', 'alpha_dash', Rule::unique('service_categories', 'slug')->ignore($categoryId)],
            'icon' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $old = DB::table('service_categories')->where('id', $categoryId)->first();
        abort_unless($old, 404);
        DB::table('service_categories')->where('id', $categoryId)->update($validated + ['updated_at' => now()]);
        $this->invalidatePublicCatalog();
        $this->writeAudit($request, 'catalog.category.updated', 'service_category', $categoryId, (array) $old,
            (array) DB::table('service_categories')->find($categoryId));

        return response()->json(['data' => DB::table('service_categories')->find($categoryId)]);
    }

    public function manageServices(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        return response()->json([
            'data' => DB::table('services')
                ->join('service_categories', 'services.category_id', '=', 'service_categories.id')
                ->select('services.*', 'service_categories.name_fa as category_name')
                ->orderBy('service_categories.display_order')
                ->orderBy('services.id')
                ->get(),
        ]);
    }

    public function storeService(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'title_fa' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', 'unique:services,slug'],
            'description' => ['nullable', 'string', 'max:4000'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'estimated_duration_minutes' => ['required', 'numeric', 'gt:0', 'max:1440'],
            'difficulty_level' => ['sometimes', Rule::in(['easy', 'medium', 'hard', 'expert'])],
            'requires_ownership_proof' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $now = now();
        $id = DB::table('services')->insertGetId($validated + [
            'difficulty_level' => 'medium',
            'requires_ownership_proof' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->invalidatePublicCatalog();
        $this->writeAudit($request, 'catalog.service.created', 'service', $id, null,
            (array) DB::table('services')->find($id));

        return response()->json(['data' => DB::table('services')->find($id)], 201);
    }

    public function updateService(Request $request, int $serviceId): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:service_categories,id'],
            'title_fa' => ['sometimes', 'required', 'string', 'max:160'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', 'alpha_dash', Rule::unique('services', 'slug')->ignore($serviceId)],
            'description' => ['nullable', 'string', 'max:4000'],
            'base_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'estimated_duration_minutes' => ['sometimes', 'numeric', 'gt:0', 'max:1440'],
            'difficulty_level' => ['sometimes', Rule::in(['easy', 'medium', 'hard', 'expert'])],
            'requires_ownership_proof' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $old = DB::table('services')->where('id', $serviceId)->first();
        abort_unless($old, 404);
        DB::table('services')->where('id', $serviceId)->update($validated + ['updated_at' => now()]);
        $this->invalidatePublicCatalog();
        $this->writeAudit($request, 'catalog.service.updated', 'service', $serviceId, (array) $old,
            (array) DB::table('services')->find($serviceId));

        return response()->json(['data' => DB::table('services')->find($serviceId)]);
    }

    public function manageAreas(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        return response()->json(['data' => DB::table('service_areas')->orderBy('city_name')->orderBy('zone_name')->get()]);
    }

    public function storeArea(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'city_name' => ['required', 'string', 'max:100'],
            'zone_name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', 'unique:service_areas,slug'],
            'center_lat' => ['required', 'numeric', 'between:-90,90'],
            'center_lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['required', 'numeric', 'gt:0', 'max:5000'],
            'minimum_trip_km' => ['sometimes', 'numeric', 'min:0', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (($validated['minimum_trip_km'] ?? 0) > $validated['radius_km']) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_DISPATCH_RANGE',
                'message' => 'حداقل مسافت اعزام نمی‌تواند از شعاع محدوده بیشتر باشد.',
            ], 422);
        }
        $now = now();
        $id = DB::table('service_areas')->insertGetId($validated + [
            'minimum_trip_km' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->writeAudit($request, 'catalog.area.created', 'service_area', $id, null,
            (array) DB::table('service_areas')->find($id));

        return response()->json(['data' => DB::table('service_areas')->find($id)], 201);
    }

    public function updateArea(Request $request, int $areaId): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'city_name' => ['sometimes', 'required', 'string', 'max:100'],
            'zone_name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', 'alpha_dash', Rule::unique('service_areas', 'slug')->ignore($areaId)],
            'center_lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'center_lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'numeric', 'gt:0', 'max:5000'],
            'minimum_trip_km' => ['sometimes', 'numeric', 'min:0', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $area = DB::table('service_areas')->where('id', $areaId)->first();
        abort_unless($area, 404);
        $radius = (float) ($validated['radius_km'] ?? $area->radius_km);
        $minimumTrip = (float) ($validated['minimum_trip_km'] ?? $area->minimum_trip_km);
        if ($minimumTrip > $radius) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_DISPATCH_RANGE',
                'message' => 'حداقل مسافت اعزام نمی‌تواند از شعاع محدوده بیشتر باشد.',
            ], 422);
        }
        DB::table('service_areas')->where('id', $areaId)->update($validated + ['updated_at' => now()]);
        $this->writeAudit($request, 'catalog.area.updated', 'service_area', $areaId, (array) $area,
            (array) DB::table('service_areas')->find($areaId));

        return response()->json(['data' => DB::table('service_areas')->find($areaId)]);
    }

    public function listTariffs(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        return response()->json([
            'data' => DB::table('service_tariffs')
                ->join('services', 'service_tariffs.service_id', '=', 'services.id')
                ->join('service_areas', 'service_tariffs.service_area_id', '=', 'service_areas.id')
                ->select('service_tariffs.*', 'services.title_fa as service_name', 'service_areas.city_name', 'service_areas.zone_name')
                ->orderByDesc('service_tariffs.effective_from')
                ->orderByDesc('service_tariffs.version')
                ->get(),
        ]);
    }

    public function storeTariff(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'service_area_id' => ['required', 'integer', 'exists:service_areas,id'],
            'base_fare' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'dispatch_fee' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'per_km_rate' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'per_minute_rate' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'minimum_total' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'minimum_trip_km' => ['required', 'numeric', 'min:0', 'max:5000'],
            'estimate_margin_percent' => ['required', 'numeric', 'between:0,100'],
            'night_multiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'night_start' => ['required', 'date_format:H:i'],
            'night_end' => ['required', 'date_format:H:i'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $area = DB::table('service_areas')->where('id', $validated['service_area_id'])->first();
        abort_unless($area, 404);
        if ((float) $validated['minimum_trip_km'] > (float) $area->radius_km) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_DISPATCH_RANGE',
                'message' => 'حداقل مسافت تعرفه نمی‌تواند از شعاع منطقه بیشتر باشد.',
            ], 422);
        }

        $validated['effective_from'] = CarbonImmutable::parse($validated['effective_from'])
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
        $validated['effective_until'] = isset($validated['effective_until'])
            ? CarbonImmutable::parse($validated['effective_until'])
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s')
            : null;

        $tariff = DB::transaction(function () use ($validated, $request): object {
            $versions = DB::table('service_tariffs')
                ->where('service_id', $validated['service_id'])
                ->where('service_area_id', $validated['service_area_id'])
                ->lockForUpdate();
            $version = (int) (clone $versions)->max('version') + 1;
            $openEnded = (clone $versions)
                ->whereNull('effective_until')
                ->orderByDesc('effective_from')
                ->first();

            if ($openEnded && $openEnded->effective_from < $validated['effective_from']) {
                DB::table('service_tariffs')->where('id', $openEnded->id)->update([
                    'effective_until' => $validated['effective_from'],
                    'updated_at' => now(),
                ]);
            }

            $overlap = (clone $versions)
                ->where('effective_from', '<', $validated['effective_until'] ?? '9999-12-31 23:59:59')
                ->where(function ($query) use ($validated) {
                    $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>', $validated['effective_from']);
                })
                ->exists();

            if ($overlap) {
                abort(422, 'بازهٔ تعرفه با نسخهٔ دیگری هم‌پوشانی دارد.');
            }

            $now = now();
            $id = DB::table('service_tariffs')->insertGetId($validated + [
                'version' => $version,
                'created_by_user_id' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $tariff = DB::table('service_tariffs')->find($id);
            $this->writeAudit($request, 'catalog.tariff.created', 'service_tariff', $id, null, (array) $tariff);
            return $tariff;
        });

        return response()->json(['data' => $tariff], 201);
    }

    private function isSuperAdmin(Request $request): bool
    {
        $user = $request->user();
        if (!$user || $user->status !== 'active') {
            return false;
        }

        return DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $user->id)
            ->where('roles.name', 'super_admin')
            ->exists();
    }

    private function invalidatePublicCatalog(): void
    {
        Cache::forget(self::PUBLIC_CATALOG_CACHE_KEY);
    }

    private function writeAudit(Request $request, string $action, string $type, int $id, ?array $old, array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'old_values' => $old ? json_encode($old, JSON_THROW_ON_ERROR) : null,
            'new_values' => json_encode($new, JSON_THROW_ON_ERROR),
            'reason' => $request->input('reason'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'FORBIDDEN_PRICING_MANAGEMENT',
            'message' => 'مدیریت خدمات و تعرفه فقط برای مدیر ارشد مجاز است.',
        ], 403);
    }
}
