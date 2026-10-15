<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Roles & Permissions (RBAC)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. customer, technician, operator, dispatcher, finance, admin
            $table->string('display_name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. bookings.create, technicians.verify
            $table->string('display_name');
            $table->string('module')->index();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        // 2. Users Table Update / Extension
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number', 15)->unique()->nullable()->after('id');
            $table->string('national_id', 10)->unique()->nullable()->after('phone_number');
            $table->string('status', 20)->default('active')->index()->after('name'); // active, suspended, pending
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number', 15)->index();
            $table->string('code', 6);
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_verifications');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'national_id', 'status']);
        });
    }
};
