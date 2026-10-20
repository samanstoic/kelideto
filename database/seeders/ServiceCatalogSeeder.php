<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'building', 'name_fa' => 'خدمات ساختمان'],
            ['slug' => 'automotive', 'name_fa' => 'خدمات خودرو'],
            ['slug' => 'cylinder-replacement', 'name_fa' => 'تعویض مغزی و سیلندر'],
            ['slug' => 'lock-repair', 'name_fa' => 'تعمیر قفل'],
            ['slug' => 'lock-opening', 'name_fa' => 'بازکردن قفل'],
            ['slug' => 'other-authorized-services', 'name_fa' => 'سایر خدمات مجاز'],
        ];

        foreach ($categories as $order => $category) {
            DB::table('service_categories')->insertOrIgnore([
                'slug' => $category['slug'],
                'name_fa' => $category['name_fa'],
                'description' => null,
                'display_order' => $order,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
    }
}
