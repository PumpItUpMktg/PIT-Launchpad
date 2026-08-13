<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Capture §5 — link each capture device to its tech's User account. Every tech is now a first-class
 * platform user (role=tech): the device is the credential, the User is the unified identity, so a
 * Job Capture → Launchpad upgrade is a role change rather than a new account. Nullable for back-compat
 * with any device provisioned before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_devices', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tech_devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
