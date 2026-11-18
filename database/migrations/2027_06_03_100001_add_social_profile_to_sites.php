<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The tenant's profile in the posting provider (Upload-Post) — the container its GBP / Facebook / Instagram accounts are connected to. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('social_profile')->nullable()->after('bing_site_url');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('social_profile');
        });
    }
};
