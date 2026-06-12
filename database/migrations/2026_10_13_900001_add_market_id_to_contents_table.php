<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3a gate redesign — a location page must know which market it targets so the
 * reviews.market publish gate can resolve. `market_id` is nullable (optional for
 * posts/service pages; posts may carry an ingest market later — not backfilled
 * here) and indexed; validation requires it only for kind=page + page_type=location
 * (a location page with no market fails closed: location.market_missing). It is
 * NOT derived from the silo (service-axis, geo-neutral) or the slug/keyword.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->foreignUlid('market_id')->nullable()->after('silo_id')
                ->constrained('markets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('market_id');
        });
    }
};
