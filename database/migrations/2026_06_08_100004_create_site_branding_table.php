<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_branding', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('logo_set')->nullable();
            $table->json('palette')->nullable();
            $table->json('typography')->nullable();
            $table->string('imagery_style')->nullable();
            $table->json('social_handles')->nullable();
            $table->string('default_share_image')->nullable();
            $table->string('default_card_type')->nullable();
            $table->string('entity_type')->nullable();
            $table->json('same_as')->nullable();
            $table->json('canonical_nap')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_branding');
    }
};
