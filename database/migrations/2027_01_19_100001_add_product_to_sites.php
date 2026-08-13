<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's product plan (Job Capture standalone vs. full Launchpad). Defaults to `launchpad` so every
 * existing tenant is unchanged; the standalone Job Capture onboarding path stamps `job_capture`, and an
 * upgrade flips it to `launchpad`. It's the upgrade lever — no data migration, since a Job Capture tenant
 * already carries the same Site + WordPress connection Launchpad uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('product')->default('launchpad')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('product');
        });
    }
};
