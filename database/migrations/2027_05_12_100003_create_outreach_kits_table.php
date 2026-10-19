<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outreach kits (§ Links): the copy a person pastes to get one link — a directory listing's descriptions
 * and categories for an office, or the pitch to a prospect — drafted once in the tenant's voice from the
 * NAP, services and office page, and kept so it is not drafted twice. One per (site, office, target).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_kits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->ulid('location_id')->nullable()->index();   // the office the kit is for; null = the brand
            $table->string('target_kind');                       // directory | prospect
            $table->string('target_domain')->index();
            $table->json('payload');
            $table->string('model')->nullable();
            $table->boolean('model_ok')->default(true);         // false = the model's copy failed and the fallback copy is in the payload
            $table->timestamp('drafted_at');
            $table->timestamps();

            $table->unique(['site_id', 'location_id', 'target_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_kits');
    }
};
