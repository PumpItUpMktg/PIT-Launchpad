<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 6 (Page Inventory) completion gate. Inventory was a pass-through with no completion flag, so
 * it could never read "complete" — the stepper never marked it done and an all-7-steps onboarding
 * check could never pass. `inventory_reviewed` is set when the operator clicks Continue (reviewing
 * is enough; no manual checkbox required to escape the step).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_states', function (Blueprint $table) {
            $table->boolean('inventory_reviewed')->default(false)->after('structure_finalized');
        });
    }

    public function down(): void
    {
        Schema::table('setup_states', function (Blueprint $table) {
            $table->dropColumn('inventory_reviewed');
        });
    }
};
