<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A page's slug must be unique only among LIVE pages. The original full unique index on
 * (site_id, slug) also covered soft-deleted rows — so a Removed (soft-deleted) page kept reserving its
 * slug, and the next build materialised the replacement at a "-2" permalink to dodge the collision.
 * Replace it with a PARTIAL unique index scoped to `deleted_at IS NULL`, so removing a page frees its
 * slug for the rebuild. (Both Postgres and SQLite support the partial-index syntax below.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'slug']);
        });

        DB::statement('CREATE UNIQUE INDEX contents_site_id_slug_active_unique ON contents (site_id, slug) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX '.(DB::getDriverName() === 'sqlite' ? '' : 'IF EXISTS ').'contents_site_id_slug_active_unique');

        Schema::table('contents', function (Blueprint $table): void {
            $table->unique(['site_id', 'slug']);
        });
    }
};
