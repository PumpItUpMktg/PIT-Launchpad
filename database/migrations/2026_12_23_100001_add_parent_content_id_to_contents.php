<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location-hub URL nesting: a town page's PARENT page — its location hub landing (the page pinned by
 * `location_id`). DELIBERATELY distinct from `parent_location_id` (which points at the physical
 * Location MODEL for board grouping); this points at the parent CONTENT so the nested permalink
 * (/montclair/springfield) and the WP post_parent chain can be built. Deferred-FK style like the
 * other content cross-links (near_dup_of_content_id, refresh_of_content_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->ulid('parent_content_id')->nullable()->index()->after('parent_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn('parent_content_id');
        });
    }
};
