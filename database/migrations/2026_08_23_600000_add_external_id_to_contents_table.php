<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Stable per-article identity carried from the news feed
            // (e.g. "googlenews:<sha1(link)>"). The §6a funnel dedups on this so
            // the hourly re-ingest of the same feed can't create the same article
            // twice. Nullable — directed/revived/backfill candidates have none.
            // Indexed on (site_id, external_id) for the per-tenant dedup lookup.
            $table->string('external_id')->nullable()->after('source_url');
            $table->index(['site_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
