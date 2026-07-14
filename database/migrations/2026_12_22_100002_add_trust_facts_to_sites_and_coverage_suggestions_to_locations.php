<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gathering relay: the trust facts the new Business step captures on the tenant record (license,
 * insured, years in business, warranty program, guarantees) — manual entry on step 1, seedable by
 * the interview. `insured` is nullable on purpose: null = unknown, distinct from "no".
 *
 * Locations gain `coverage_suggestions`: extraction's unresolved coverage phrases ("30 min from
 * the shop") and town candidates that CONFLICT with another location's served_towns land here as
 * operator prompts — never as saved served_towns rows (one town, one location).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('license_number')->nullable();
            $table->boolean('insured')->nullable();
            $table->unsignedSmallInteger('years_in_business')->nullable();
            $table->text('warranty_program')->nullable();
            $table->text('guarantees')->nullable();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->json('coverage_suggestions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['license_number', 'insured', 'years_in_business', 'warranty_program', 'guarantees']);
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('coverage_suggestions');
        });
    }
};
