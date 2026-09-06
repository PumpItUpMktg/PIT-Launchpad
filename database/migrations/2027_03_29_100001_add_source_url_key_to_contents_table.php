<?php

use App\ContentEngine\ArticleUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            // Canonical dedup key for the candidate's external article URL (host w/o www + path, scheme-
            // agnostic, no query/fragment). The §6a funnel dedups on this so the SAME article ingested with
            // a different external_id / tracking params / www variance can't create a second candidate.
            // Nullable — directed/revived candidates + Google-News items with no clean link have none.
            // Indexed on (site_id, source_url_key) for the per-tenant dedup lookup.
            $table->string('source_url_key')->nullable()->after('external_id');
            $table->index(['site_id', 'source_url_key']);
        });

        // Backfill existing candidates from their stored source_url (chunked; updates a non-filtered column
        // so chunk() is stable). Derives the same key the funnel will write going forward.
        DB::table('contents')
            ->whereNotNull('source_url')
            ->whereNull('source_url_key')
            ->orderBy('id')
            ->select(['id', 'source_url'])
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $key = ArticleUrl::key($row->source_url);
                    if ($key !== null) {
                        DB::table('contents')->where('id', $row->id)->update(['source_url_key' => $key]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'source_url_key']);
            $table->dropColumn('source_url_key');
        });
    }
};
