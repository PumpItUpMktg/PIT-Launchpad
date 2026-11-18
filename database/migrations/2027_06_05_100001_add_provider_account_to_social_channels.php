<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant can span several posting-provider profiles (one per Google account that holds some of its
 * listings), so each channel remembers which profile exposes it and publishes through that one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_channels', function (Blueprint $table): void {
            $table->string('provider_account', 120)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('social_channels', function (Blueprint $table): void {
            $table->dropColumn('provider_account');
        });
    }
};
