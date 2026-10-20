<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->text('reason')->nullable();
            $table->string('request_id', 64)->nullable()->index();
        });

        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('title', 200);
            $table->longText('body');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 120)->unique();
            $table->json('setting_value');
            $table->string('category', 40)->default('general')->index();
            $table->boolean('is_sensitive')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('security_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_key', 120)->unique();
            $table->json('policy_value');
            $table->string('reason', 1000);
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_policies');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('content_pages');
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn(['reason', 'request_id']);
        });
    }
};
