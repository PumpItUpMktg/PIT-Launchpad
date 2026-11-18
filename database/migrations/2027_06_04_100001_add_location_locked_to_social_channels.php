<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An operator can tie a GBP channel the sync could not place (a listing named without its town) to an
 * office by hand; `location_locked` keeps that assignment through later syncs, which otherwise re-match
 * from the provider's name and would clear it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_channels', function (Blueprint $table): void {
            $table->boolean('location_locked')->default(false)->after('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_channels', function (Blueprint $table): void {
            $table->dropColumn('location_locked');
        });
    }
};
