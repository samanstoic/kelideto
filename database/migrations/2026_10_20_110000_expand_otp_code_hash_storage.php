<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('otp_verifications')->delete();
        if (Schema::hasTable('notifications_log')) {
            DB::table('notifications_log')->where('template_key', 'auth_otp')->delete();
        }

        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->string('code', 255)->change();
        });
    }

    public function down(): void
    {
        DB::table('otp_verifications')->delete();

        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->string('code', 6)->change();
        });
    }
};