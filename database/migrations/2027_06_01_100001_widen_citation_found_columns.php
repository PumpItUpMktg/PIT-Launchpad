<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A directory listing URL (and the name / address scraped off it) can run past 255 characters — a tracked
 * listing link with its query string did, and because the scanner writes each found domain inside its loop,
 * the one long value failed the whole scan ("value too long for type character varying(255)"). Text columns:
 * what the SERP returns is what gets stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->text('found_url')->nullable()->change();
            $table->text('found_name')->nullable()->change();
            $table->text('found_address')->nullable()->change();
        });
        Schema::table('citation_found_domains', function (Blueprint $table): void {
            $table->text('found_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->string('found_url')->nullable()->change();
            $table->string('found_name')->nullable()->change();
            $table->string('found_address')->nullable()->change();
        });
        Schema::table('citation_found_domains', function (Blueprint $table): void {
            $table->string('found_url')->nullable()->change();
        });
    }
};
