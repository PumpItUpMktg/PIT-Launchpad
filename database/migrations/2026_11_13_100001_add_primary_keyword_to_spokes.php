<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cannibalization-safe keyword assignment (auto-arrange Pass D). Each page (pillar,
 * sub-hub, own-page core) gets a distinct `primary_keyword` so no two pages target the
 * same query — pillar = category head, sub-hub = umbrella distinct from its children,
 * own-page core = its specific term. `keyword_collision_score` persists the cosine behind
 * a detected collision so the collision threshold can be tuned from live output.
 *
 * The keyword carries its OWN provenance (`keyword_source`: auto|confirmed) — separate from
 * the structural `arrangement_source`, since a confirmed *demotion* must not freeze the
 * sub-hub's keyword (a distinct decision). Pass D writes only over a non-confirmed keyword,
 * so a confirmed keyword survives a re-run. The keyword also feeds generation later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->string('primary_keyword')->nullable()->after('is_sub_hub');
            $table->string('keyword_source')->nullable()->after('primary_keyword');
            $table->double('keyword_collision_score')->nullable()->after('keyword_source');
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->dropColumn(['primary_keyword', 'keyword_source', 'keyword_collision_score']);
        });
    }
};
