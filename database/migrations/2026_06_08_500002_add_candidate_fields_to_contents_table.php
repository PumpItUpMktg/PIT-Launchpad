<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Reactive attribution (source_name required for reactive posts;
            // source_url populated only when a clean canonical URL resolves).
            $table->string('source_name')->nullable();
            $table->string('source_url')->nullable();

            // Candidate / relevance fields from the §6a funnel.
            // matched_silo_id has no DB FK (additive ALTER; matches the §1
            // deferred-FK pattern) — the relation is enforced at the model level.
            $table->ulid('matched_silo_id')->nullable()->index();
            $table->text('angle_hint')->nullable();
            $table->decimal('relevance_score', 5, 4)->nullable();
            $table->boolean('local_relevance')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['source_name', 'source_url', 'matched_silo_id', 'angle_hint', 'relevance_score', 'local_relevance']);
        });
    }
};
