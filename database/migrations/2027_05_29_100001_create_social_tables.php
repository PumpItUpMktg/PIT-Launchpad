<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A connected destination: a GBP location, a Facebook Page, an Instagram account — identified by
        // the posting provider's channel id (SoMePoster's connected-profile id). GBP channels hang off a
        // physical Location (a job posts to the GBP of the location that serves its town).
        Schema::create('social_channels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('network', 20);
            $table->string('provider', 30)->default('someposter');
            $table->string('provider_channel_id');
            $table->string('label')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('max_per_day')->nullable();   // cadence cap; null = no cap (decided later)
            $table->timestamps();

            $table->unique(['site_id', 'network', 'provider_channel_id']);
            $table->index(['site_id', 'network', 'enabled']);
        });

        // One share: a subject (a job or a post) composed for one channel, with the trigger that queued it,
        // the copy as sent, the UTM-tagged link, and the provider's post id / permalink once it is out.
        Schema::create('social_posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('social_channel_id')->constrained('social_channels')->cascadeOnDelete();
            $table->string('subject_type', 20);   // job | post
            $table->ulid('subject_id');
            $table->string('network', 20);
            $table->string('trigger', 30);
            $table->string('status', 20)->default('queued');
            $table->text('copy');
            $table->json('hashtags')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('link', 2048)->nullable();
            $table->string('cta', 30)->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->string('provider_post_id')->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->text('error')->nullable();
            $table->json('snapshot')->nullable();   // the numbers / facts that fired a re-share rule
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['social_channel_id', 'status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_channels');
    }
};
