<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Setup-redesign relay: the guided flow consolidates 7 steps → 5 (Territory merges into
 * WhereYouWork=4; Structure/Inventory/Approve merge into Plan=5; the Build/Grow phases shift
 * 8→6 / 9→7). Remap the persisted `setup_states.current_step` integers so every tenant resumes
 * on the equivalent step. Completion flags are untouched — they keep their meaning
 * (`structure_finalized`/`inventory_reviewed` become internal to Plan).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Old 5 (Structure), 6 (Inventory), 7 (Approve) all live inside the new Plan step.
        DB::table('setup_states')->whereIn('current_step', [5, 6, 7])->update(['current_step' => 5]);
        DB::table('setup_states')->where('current_step', 8)->update(['current_step' => 6]);
        DB::table('setup_states')->where('current_step', 9)->update(['current_step' => 7]);
        // 1–4 are unchanged (Business / ConnectWordpress / Brand / Territory→WhereYouWork).
    }

    public function down(): void
    {
        // Approximate reverse: Plan resumes at the old Structure entry; phases shift back.
        DB::table('setup_states')->where('current_step', 7)->update(['current_step' => 9]);
        DB::table('setup_states')->where('current_step', 6)->update(['current_step' => 8]);
        // current_step 5 stays 5 (old Structure) — the closest resume point.
    }
};
