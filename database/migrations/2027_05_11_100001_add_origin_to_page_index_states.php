<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a verdict row came from — which of Search Console's two Pages views it belongs to.
 *
 *   content    a page Launchpad published (in the sitemap; Search Console's "All submitted pages")
 *   job        a published Job Capture page (also in the sitemap)
 *   discovered a URL Google has shown in Search that Launchpad did not publish — legacy posts, archives
 *              (Search Console's "All known pages" minus the submitted ones)
 *
 * Until now the table held only submitted URLs, so "no content id" meant "a job". With the all-known
 * capture inspecting legacy URLs into the same table, the origin has to be explicit or the job pages and
 * the legacy pages would be indistinguishable. Backfill: the only rows without a content id today are jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_index_states', function (Blueprint $table): void {
            $table->string('origin', 16)->default('content')->after('content_id');
            $table->index(['site_id', 'origin']);
        });

        DB::table('page_index_states')->whereNull('content_id')->update(['origin' => 'job']);
    }

    public function down(): void
    {
        Schema::table('page_index_states', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'origin']);
            $table->dropColumn('origin');
        });
    }
};
