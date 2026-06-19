<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted auto-arrange flags (increment 4b). A run writes the recommended structure AND
 * its flagged-for-confirm list here (replace-on-run), so the operator prune surface can show
 * accept/dismiss controls without re-running the (paid) embedding passes on every page load.
 * Each row points at the affected spoke and carries the rationale (message + candidates +
 * score). Resolving a flag (accept/dismiss) deletes its row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrange_flags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('site_id')->index();
            $table->string('spoke_id')->nullable()->index();
            $table->string('type');
            $table->text('message');
            $table->json('candidates')->nullable();
            $table->double('score')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrange_flags');
    }
};
