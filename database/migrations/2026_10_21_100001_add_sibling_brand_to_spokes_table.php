<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Routing-layer handoff hint for a fringe spoke (Phase 2 expansion): the sibling
 * brand / partner a genuinely out-of-lane candidate should route to (e.g. mold →
 * "Trusted Mold"). Phase 2 only tags + records it; the separate Routing layer reads it
 * to build the out-of-lane router / B2B partner pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->string('sibling_brand')->nullable()->after('connection_note');
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->dropColumn('sibling_brand');
        });
    }
};
