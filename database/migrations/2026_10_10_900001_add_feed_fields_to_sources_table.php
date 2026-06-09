<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // §6a Phase 2 — turn Source into the uniform feed entity. `origin` is
            // pure provenance (generated|client); everything past parse is
            // origin-blind. `silo_id` (already present) is the routing hint.
            $table->string('origin')->default('generated')->after('type');

            // The fetchable feed URL: a Google News RSS search URL for generated
            // feeds, the client's RSS/Atom URL for client feeds. Fetch strategy
            // branches on this URL's host, not on origin.
            $table->text('url')->nullable()->after('config');

            // For generated feeds, the (keyword, market) signature this feed was
            // projected from — the reconcile job's idempotent upsert key. Null for
            // client feeds.
            $table->string('derived_from')->nullable()->after('url');

            // Human label for the panel (feed title / client-given for client;
            // auto-derived for generated).
            $table->string('label')->nullable()->after('derived_from');

            // Fetch telemetry — drives scheduling/dedup and the per-feed health
            // badge (0-items-for-N-days / repeated-failure flag).
            $table->timestamp('last_fetched_at')->nullable()->after('schedule');
            $table->timestamp('last_item_at')->nullable()->after('last_fetched_at');
            $table->text('last_error')->nullable()->after('last_item_at');

            // One generated feed per (site, derived signature); client rows carry
            // a null signature and are unconstrained (NULLs are distinct).
            $table->unique(['site_id', 'derived_from']);
            $table->index(['site_id', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'derived_from']);
            $table->dropIndex(['site_id', 'origin']);
            $table->dropColumn([
                'origin',
                'url',
                'derived_from',
                'label',
                'last_fetched_at',
                'last_item_at',
                'last_error',
            ]);
        });
    }
};
