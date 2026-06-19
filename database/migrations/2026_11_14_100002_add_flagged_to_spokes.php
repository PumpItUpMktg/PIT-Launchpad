<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * auto-arrange judgment-call marker (increment 4b, corrected model). A spoke's arrangement is
 * one of three states: `arrangement_source=auto` (a settled default), `auto` + `flagged=true`
 * (an auto-applied judgment call awaiting operator accept/dismiss), or `confirmed` (signed off).
 * Every pass auto-applies its best pick AND flags the judgment calls; an unresolved flag blocks
 * Finalize, which is what makes confident auto-apply safe. Resolving (accept/dismiss) clears the
 * flag and confirms. A re-run re-evaluates flagged-but-unresolved (still auto) under the
 * margin-to-reflip; confirmed is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->boolean('flagged')->default(false)->after('keyword_source');
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->dropColumn('flagged');
        });
    }
};
