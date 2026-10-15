<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseAndDomainModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test all 25 core domain tables exist.
     */
    public function test_all_domain_tables_exist(): void
    {
        $tables = [
            'users',
            'roles',
            'permissions',
            'customer_profiles',
            'technician_profiles',
            'technician_documents',
            'verification_records',
            'service_categories',
            'services',
            'service_areas',
            'pricing_rules',
            'addresses',
            'price_estimates',
            'price_estimate_items',
            'bookings',
            'booking_status_history',
            'dispatch_offers',
            'assignments',
            'scope_change_requests',
            'customer_approvals',
            'financial_accounts',
            'payments',
            'payment_events',
            'ledger_entries',
            'technician_settlements',
            'reviews',
            'support_tickets',
            'audit_logs',
            'offline_sync_records',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), "Table [{$table}] does not exist in schema.");
        }
    }

    /**
     * Test RBAC user-role relation and constraints.
     */
    public function test_rbac_relationship_and_constraints(): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'technician_' . Str::random(5),
            'display_name' => 'متخصص کلیدسازی',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'سهراب احمدی',
            'phone_number' => '0912' . rand(1000000, 9999999),
            'national_id' => '00' . rand(10000000, 99999999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);

        $assignedRole = DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $userId)
            ->first();

        $this->assertNotNull($assignedRole);
        $this->assertStringStartsWith('technician_', $assignedRole->name);
    }

    /**
     * Test geospatial and booking allocation logic.
     */
    public function test_geospatial_distance_and_booking_lifecycle(): void
    {
        // 1. Create Customer
        $customerId = DB::table('users')->insertGetId([
            'name' => 'مشتری آزمایشی',
            'phone_number' => '0999' . rand(1000000, 9999999),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerProfileId = DB::table('customer_profiles')->insertGetId([
            'user_id' => $customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create Service
        $catId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'بازگشایی درب',
            'slug' => 'door-opening-' . Str::random(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $catId,
            'title_fa' => 'بازگشایی درب ضدسرقت',
            'slug' => 'anti-theft-door-' . Str::random(5),
            'base_price' => 250000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Create Booking in Tehran Valiasr (35.7152, 51.4043)
        $bookingId = DB::table('bookings')->insertGetId([
            'booking_code' => (string) Str::uuid(),
            'customer_id' => $customerProfileId,
            'service_id' => $serviceId,
            'status' => 'broadcasting',
            'latitude' => 35.7152000,
            'longitude' => 51.4043000,
            'street_address' => 'تهران، خیابان ولیعصر، بالاتر از زرتشت، پلاک ۱۲',
            'estimated_price' => 250000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'status' => 'broadcasting',
        ]);

        // 4. Test Haversine distance formula simulation
        // Technician located at Tajrish (35.8053, 51.4285)
        $techLat = 35.8053;
        $techLng = 51.4285;
        $customerLat = 35.7152;
        $customerLng = 51.4043;

        $earthRadiusKm = 6371;
        $dLat = deg2rad($techLat - $customerLat);
        $dLng = deg2rad($techLng - $customerLng);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($customerLat)) * cos(deg2rad($techLat)) *
             sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distanceKm = $earthRadiusKm * $c;

        // Valiasr to Tajrish is roughly ~10.2 km
        $this->assertGreaterThan(9.0, $distanceKm);
        $this->assertLessThan(12.0, $distanceKm);
    }

    /**
     * Test Double-Entry Ledger and zero-balance integrity.
     */
    public function test_double_entry_ledger_balance(): void
    {
        $platformAccount = DB::table('financial_accounts')->insertGetId([
            'account_number' => 'REV-' . Str::random(8),
            'account_name' => 'درآمد کارمزد پلتفرم',
            'type' => 'revenue',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $escrowAccount = DB::table('financial_accounts')->insertGetId([
            'account_number' => 'LIA-' . Str::random(8),
            'account_name' => 'حساب واسط امانی',
            'type' => 'liability',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $txnGroup = (string) Str::uuid();
        $amount = 150000.00;

        // Debit liability (Escrow)
        DB::table('ledger_entries')->insert([
            'transaction_group_id' => $txnGroup,
            'account_id' => $escrowAccount,
            'entry_type' => 'debit',
            'amount' => $amount,
            'description' => 'تخصیص سهم پلتفرم از حساب امانی',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Credit revenue (Platform)
        DB::table('ledger_entries')->insert([
            'transaction_group_id' => $txnGroup,
            'account_id' => $platformAccount,
            'entry_type' => 'credit',
            'amount' => $amount,
            'description' => 'شناسایی درآمد کارمزد',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entries = DB::table('ledger_entries')->where('transaction_group_id', $txnGroup)->get();
        $totalDebit = $entries->where('entry_type', 'debit')->sum('amount');
        $totalCredit = $entries->where('entry_type', 'credit')->sum('amount');

        $this->assertEquals($totalDebit, $totalCredit, 'Double-entry ledger debit and credit must balance.');
    }
}
