<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Channels now come from the posting provider's connected profiles (synced), never typed in.
        Schema::table('social_channels', function (Blueprint $table) {
            $table->string('provider_name')->nullable()->after('provider_channel_id');     // the profile's name as the provider shows it
            $table->string('provider_address')->nullable()->after('provider_name');        // a GBP profile's address, for matching
            $table->string('provider_place_id')->nullable()->after('provider_address');    // a GBP profile's place id, the hard match key
            $table->timestamp('synced_at')->nullable()->after('max_per_day');
        });

        // A re-share the rules propose for the operator's approval (or that a rule auto-sent and records here).
        Schema::create('social_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->string('trigger', 30);
            $table->string('status', 20)->default('open');   // open | approved | skipped | auto
            $table->string('reason', 500);
            $table->json('snapshot')->nullable();
            $table->json('networks');                        // proposed networks, pre-ticked
            $table->json('location_ids')->nullable();        // the GBP locations the post resolves to (null = every GBP)
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status', 'created_at']);
            $table->index(['content_id', 'trigger', 'status']);
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignUlid('social_suggestion_id')->nullable()->after('social_channel_id')->constrained('social_suggestions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_suggestion_id');
        });
        Schema::dropIfExists('social_suggestions');
        Schema::table('social_channels', function (Blueprint $table) {
            $table->dropColumn(['provider_name', 'provider_address', 'provider_place_id', 'synced_at']);
        });
    }
};
