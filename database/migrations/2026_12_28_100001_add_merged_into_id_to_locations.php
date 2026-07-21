<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The NAP-reconcile tombstone: when two Location rows describe the SAME physical place (a bare intake
 * row + a GBP-enriched row), the reconcile folds one into the other and points the retired row here at
 * the survivor. A row with `merged_into_id` set is hidden by the model's global scope — it stays in the
 * table (reversible: null the column to restore) but disappears from every normal query, so it never
 * grows a duplicate hub page. Indexed ULID, deferred-FK style like the other cross-cycle pins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->ulid('merged_into_id')->nullable()->index()->after('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('merged_into_id');
        });
    }
};
