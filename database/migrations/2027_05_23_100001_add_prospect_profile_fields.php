<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * § Links — the organisation profile behind a link prospect: who they are, where, who to talk to, what
 * they offer a sponsor or member (and whether a website link is part of it), and a thumbnail. Read from
 * their page on a click, never on a schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_prospects', function (Blueprint $table): void {
            $table->json('profile')->nullable();
            $table->timestamp('profile_read_at')->nullable();
            $table->boolean('profile_model_ok')->nullable();     // null = never read; false = facts only, the model did not answer
            $table->string('profile_error')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('link_prospects', function (Blueprint $table): void {
            $table->dropColumn(['profile', 'profile_read_at', 'profile_model_ok', 'profile_error', 'thumbnail_url']);
        });
    }
};
