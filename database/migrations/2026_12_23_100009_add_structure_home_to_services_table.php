<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            // The inversion of catalog-first: a service maps ONTO the demand-derived structure. Its
            // structure_home is the keyword cluster (≈ silo) it belongs to, assigned by service→cluster
            // matching at derivation. A service that matches nothing is pinned to the nearest cluster and
            // flagged for operator review.
            $table->foreignUlid('structure_home_cluster_id')->nullable()->after('silo_role');
            $table->boolean('structure_home_flagged')->default(false)->after('structure_home_cluster_id');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['structure_home_cluster_id', 'structure_home_flagged']);
        });
    }
};
