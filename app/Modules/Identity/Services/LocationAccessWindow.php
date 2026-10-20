<?php

namespace App\Modules\Identity\Services;

use Illuminate\Support\Facades\DB;

class LocationAccessWindow
{
    public function hours(): int
    {
        $value = DB::table('security_policies')
            ->where('policy_key', 'admin.location_retention_hours')
            ->value('policy_value');

        if ($value === null) {
            return 3;
        }

        $settings = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return min(max((int) ($settings['hours'] ?? 3), 1), 8);
    }
}
