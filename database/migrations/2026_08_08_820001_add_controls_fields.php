<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // §7b feeds control: enable/disable a §6a source feed.
            $table->boolean('enabled')->default(true);
        });

        Schema::table('sites', function (Blueprint $table) {
            // §5 per-tenant budget ceiling (sampling units/period). Degrades
            // coverage/low tiers first. Metered billing is deferred (§9 #6);
            // usage-against-budget is surfaced read-only.
            $table->unsignedInteger('budget_ceiling')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('budget_ceiling');
        });
    }
};
