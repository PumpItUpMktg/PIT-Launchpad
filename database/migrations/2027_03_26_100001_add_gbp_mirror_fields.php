<?php

use App\Citations\NapProfileHydrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the GBP the source of truth for a location's NAP.
 *
 * - `locations.website` — the business website Google Places already returns (the detail request asks for it);
 *   previously dropped at import. Stored so it can be mirrored into the NAP and matched against citations.
 * - `location_nap_profiles.gbp_synced` — a per-field snapshot of the last GBP value written into the NAP. It's
 *   how {@see NapProfileHydrator} tells "the operator hasn't touched this field, so keep it
 *   tracking the GBP" from "the operator deliberately overrode it, so preserve their value" on re-sync. Null on
 *   legacy rows → first sync fills only blanks (never clobbers pre-existing manual data), then tracks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('website')->nullable()->after('gbp_url');
        });

        Schema::table('location_nap_profiles', function (Blueprint $table): void {
            $table->jsonb('gbp_synced')->nullable()->after('photo_urls');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('website');
        });

        Schema::table('location_nap_profiles', function (Blueprint $table): void {
            $table->dropColumn('gbp_synced');
        });
    }
};
