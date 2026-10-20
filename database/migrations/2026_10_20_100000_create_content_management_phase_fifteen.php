<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_pages', function (Blueprint $table) {
            $table->string('content_type', 20)->default('page')->index();
            $table->string('excerpt', 500)->nullable();
            $table->string('meta_title', 200)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->unsignedBigInteger('og_image_id')->nullable()->index();
            $table->timestamp('scheduled_at')->nullable()->index();
        });

        Schema::create('content_media', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('original_name', 180);
            $table->string('file_path', 500)->unique();
            $table->string('mime_type', 40);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('content_pages', function (Blueprint $table) {
            $table->foreign('og_image_id')->references('id')->on('content_media')->nullOnDelete();
        });

        Schema::create('content_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('source_path', 220)->unique();
            $table->string('target_path', 220);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_redirects');
        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropForeign(['og_image_id']);
        });
        Schema::dropIfExists('content_media');
        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropIndex(['content_type']);
            $table->dropIndex(['og_image_id']);
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn([
                'content_type', 'excerpt', 'meta_title', 'meta_description',
                'canonical_url', 'og_image_id', 'scheduled_at',
            ]);
        });
    }
};
