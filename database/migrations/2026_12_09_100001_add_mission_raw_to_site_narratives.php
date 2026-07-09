<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's own mission wording when they opted into AI enhancement — `mission` then carries the
 * AI-polished statement the pages render, and `mission_raw` keeps what the client actually typed
 * (provenance + the re-polish source). Null = the mission is verbatim (no enhancement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_narratives', function (Blueprint $table) {
            $table->text('mission_raw')->nullable()->after('mission');
        });
    }

    public function down(): void
    {
        Schema::table('site_narratives', function (Blueprint $table) {
            $table->dropColumn('mission_raw');
        });
    }
};
