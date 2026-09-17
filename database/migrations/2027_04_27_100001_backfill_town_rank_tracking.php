<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the Town Rank wall's membership a FLAG, not an inference.
 *
 * The wall used to include "every keyword that has a town-rank scan" on top of the two flags. That made
 * removal impossible: clearing the flags left a scanned keyword on the wall forever. So the scanned set is
 * written into the flag once, here, and the wall rule becomes flag-only — removable, and reversible by
 * adding the keyword back (its scans are never deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('keywords')
            ->whereIn('id', fn ($q) => $q->select('keyword_id')->distinct()->from('town_rank_scans'))
            ->where('track_town_rank', false)
            ->update(['track_town_rank' => true]);
    }

    public function down(): void
    {
        // One-way: the flag now carries membership, and which rows it was inferred for is not recoverable.
    }
};
