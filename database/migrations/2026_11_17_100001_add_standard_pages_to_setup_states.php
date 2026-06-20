<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard-pages config + the Build phase state on the per-site setup_state:
 * - `standard_pages` — which optional standard pages the client accepted (type => bool).
 * - `intake_flags` — interim seam for not-yet-modeled intake gates (financing / team).
 * - `build_status` — the Build phase between Approve and Grow (null|building|live).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_states', function (Blueprint $table) {
            $table->json('standard_pages')->nullable()->after('fresh_content');
            $table->json('intake_flags')->nullable()->after('standard_pages');
            $table->string('build_status')->nullable()->after('intake_flags');
        });
    }

    public function down(): void
    {
        Schema::table('setup_states', function (Blueprint $table) {
            $table->dropColumn(['standard_pages', 'intake_flags', 'build_status']);
        });
    }
};
