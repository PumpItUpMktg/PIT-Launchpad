<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citations — make the scan honest. A run that fails records why (and closes, so a card can't sit on
 * "Scanning…" forever); a listing the scan stops finding counts its misses before it is called lost; and a
 * service-area business (GBP with a hidden street address) can carry a canonical NAP without a street/ZIP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citation_scan_runs', function (Blueprint $table): void {
            $table->text('error')->nullable()->after('finished_at');
        });
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('missed_scans')->default(0)->after('last_scanned_at');
        });
        Schema::table('location_nap_profiles', function (Blueprint $table): void {
            $table->string('address_1')->nullable()->change();
            $table->string('postal')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('citation_scan_runs', function (Blueprint $table): void {
            $table->dropColumn('error');
        });
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->dropColumn('missed_scans');
        });
        Schema::table('location_nap_profiles', function (Blueprint $table): void {
            $table->string('address_1')->nullable(false)->change();
            $table->string('postal')->nullable(false)->change();
        });
    }
};
