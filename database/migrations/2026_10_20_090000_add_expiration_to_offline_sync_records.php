<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_sync_records', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();
        });

        DB::table('offline_sync_records')->whereNull('expires_at')->orderBy('id')->chunkById(500, function ($records): void {
            foreach ($records as $record) {
                DB::table('offline_sync_records')
                    ->where('id', $record->id)
                    ->update(['expires_at' => \Illuminate\Support\Carbon::parse($record->created_at)->addDays(7)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('offline_sync_records', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
