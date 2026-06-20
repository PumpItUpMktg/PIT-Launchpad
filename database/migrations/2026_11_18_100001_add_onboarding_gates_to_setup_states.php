<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified onboarding gates: the two new steps folded into the guided flow between Business and
 * Territory. `deps_ready` — WordPress is connected, verified, and prepped (companion plugin +
 * Elementor) at step 2; `brand_pushed` — the brand kit pushed to the prepped site at step 3.
 * Brand's prerequisite is deps_ready, so the brand push structurally cannot run before WordPress
 * is ready (the /brand-kit 404 can't recur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_states', function (Blueprint $table) {
            $table->boolean('deps_ready')->default(false)->after('services_done');
            $table->boolean('brand_pushed')->default(false)->after('deps_ready');
        });
    }

    public function down(): void
    {
        Schema::table('setup_states', function (Blueprint $table) {
            $table->dropColumn(['deps_ready', 'brand_pushed']);
        });
    }
};
