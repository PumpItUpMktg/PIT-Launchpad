<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page's OWN service — the specific §1 Service a service page is about. A service page is pinned
 * to its silo (`silo_id`), but a silo can hold a cluster of sibling services (toilet replacement /
 * installation / repair). Without a per-page service link, PageGroundingAssembler handed the drafter
 * EVERY service in the silo, so a /toilet-replacement page could draft a sibling's copy. This column
 * carries the page's subject so grounding scopes to it.
 *
 * Deferred-FK ULID (no DB-enforced constraint; additive ALTER) — matches the §1 pattern for
 * matched_silo_id / near_dup_of_content_id. Resolved at the model level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->ulid('primary_service_id')->nullable()->index()->after('silo_id');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('primary_service_id');
        });
    }
};
