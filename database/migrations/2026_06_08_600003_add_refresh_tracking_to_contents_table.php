<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Denormalized refresh cache for cheap list reads — RefreshEvent
            // remains the source of truth (full history + trigger per refresh).
            $table->timestamp('last_refreshed_at')->nullable();
            $table->unsignedInteger('refresh_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['last_refreshed_at', 'refresh_count']);
        });
    }
};
