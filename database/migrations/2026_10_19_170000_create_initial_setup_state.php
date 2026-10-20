<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
        });

        Schema::create('installation_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
        });

        DB::table('installation_state')->insert(['id' => 1]);

        $existingSuperAdminId = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.name', 'super_admin')
            ->value('role_user.user_id');
        if ($existingSuperAdminId) {
            DB::table('installation_state')->where('id', 1)->update([
                'completed_by_user_id' => $existingSuperAdminId,
                'completed_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_state');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
