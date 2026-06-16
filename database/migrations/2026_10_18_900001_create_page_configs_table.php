<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-page operator config — the USER-OWNED layer (AI never authors these). The
 * composer re-injects them on every compose, so a repush preserves them while the
 * generated layer (H1/body/FAQ) refreshes. One row per page (content).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id');
            $table->foreignUlid('content_id')->unique();
            $table->string('hero_variant')->default('cta'); // cta | form
            $table->text('form_embed')->nullable();          // generic embed (GHL form first use)
            $table->string('phone_override')->nullable();
            $table->string('hero_image_override')->nullable();
            $table->string('market_ref')->nullable();        // location-page market binding
            $table->timestamps();

            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_configs');
    }
};
