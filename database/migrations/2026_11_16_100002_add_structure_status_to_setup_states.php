<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3's "building your structure" progress state for the on-entry engine chain
 * (silo-gen → silo-volume → auto-arrange): null = not started, building, ready, failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_states', function (Blueprint $table) {
            $table->string('structure_status')->nullable()->after('structure_finalized');
        });
    }

    public function down(): void
    {
        Schema::table('setup_states', function (Blueprint $table) {
            $table->dropColumn('structure_status');
        });
    }
};
