<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_clusters', function (Blueprint $table): void {
            // A demand cluster of corpus terms (Part 2). Members link back via keyword_corpus.cluster_id.
            // The head is the term the silo will be named/hubbed by; SERP validation is on head candidates
            // only. Derivation (Part 3) turns viable clusters into silos.
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();

            $table->string('label')->nullable();          // Claude-assigned cluster name
            $table->string('head_term')->nullable();       // display head (the hub name)
            $table->string('head_canonical')->nullable();
            $table->string('intent')->nullable();          // the head's intent
            $table->unsignedInteger('volume')->nullable(); // the head's volume
            $table->unsignedInteger('member_count')->default(0);

            $table->boolean('dropped')->default(false);    // Claude flagged off-trade → not a silo
            // SERP-overlap validation on the head candidates: unvalidated | confirmed | split_signal | skipped
            $table->string('serp_status')->default('unvalidated');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_clusters');
    }
};
