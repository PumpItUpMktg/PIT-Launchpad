<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IndexNow key per site — the control plane owns it (generated on first use), pushes it to the
 * companion plugin (which serves it at /{key}.txt to prove domain ownership), and submits published
 * URLs to IndexNow with it. Nullable until first deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('indexnow_key')->nullable()->after('ga4_property');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('indexnow_key');
        });
    }
};
