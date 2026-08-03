<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §5 city-keyword tracking (Phase 2): a keyword may be pinned to a specific Market so its LOCAL-pack
 * grid is pulled from THAT city — city keywords ("{service} {city}") assigned to priority-city
 * location pages track their own market, while ordinary silo keywords leave it null and keep using
 * the site's single priority market (unchanged). Deferred-FK ULID (nullable, indexed) like the other
 * cross-cutting keyword pointers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->ulid('market_id')->nullable()->index()->after('target_content_id');
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->dropColumn('market_id');
        });
    }
};
