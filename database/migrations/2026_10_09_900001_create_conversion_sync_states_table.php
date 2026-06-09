<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-tenant, per-source incremental cursor for the conversion ingest job
        // (so a run never re-pulls full history). Ingest infrastructure — distinct
        // from the Conversion rows the dashboard reads.
        Schema::create('conversion_sync_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_sync_states');
    }
};
