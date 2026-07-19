<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_corpus', function (Blueprint $table): void {
            // The tenant-scoped keyword corpus the keyword-first structure generator accumulates BEFORE
            // any structure exists (Part 1). One row per canonical term per tenant; clustering (Part 2)
            // and derivation (Part 3) read these rows. Not trade-shared — per-tenant by design.
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();

            $table->string('term');                 // human display term (highest-volume variant)
            $table->string('canonical');            // normalized dedup key (singular/lowercase/…)
            $table->unsignedInteger('volume')->nullable();
            $table->unsignedInteger('difficulty')->nullable();
            $table->decimal('competition', 5, 4)->nullable();
            $table->string('intent')->nullable();   // IntentLevel value (transactional/commercial/informational)
            $table->string('source')->default('expansion'); // 'seed' | 'expansion'
            $table->string('seed_term')->nullable(); // which seed expanded to this term

            // Operator disposition — set on the corpus surface, NEVER wiped by re-accumulation.
            $table->string('disposition')->nullable(); // null = undecided | 'kept' | 'dismissed'

            // Filled by clustering (Part 2); null until then.
            $table->ulid('cluster_id')->nullable()->index();

            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'canonical']); // one row per canonical term per tenant
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_corpus');
    }
};
