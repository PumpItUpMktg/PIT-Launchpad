<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The build manifest: every page across the three sources (Standard / Service / Location)
 * assembled when a site is approved. Each row carries its source, content recipe, lifecycle
 * status, priority (build order), and whether it needs review before publish. Unique on
 * (site, source, key) so re-assembly is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('source');                 // BuildSource
            $table->string('page_key');               // standard type value | spoke id | coverage area id
            $table->string('title');
            $table->string('recipe');                 // composition recipe key (VoiceKit-injected)
            $table->string('status')->default('queued'); // BuildStatus
            $table->unsignedInteger('priority')->default(500);
            $table->boolean('review_required')->default(false);
            $table->foreignUlid('spoke_id')->nullable(); // service pages link back to the spoke
            $table->timestamps();

            $table->unique(['site_id', 'source', 'page_key']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_pages');
    }
};
