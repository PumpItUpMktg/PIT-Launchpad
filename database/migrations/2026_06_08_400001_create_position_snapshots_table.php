<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('keyword_id')->constrained()->cascadeOnDelete();
            // Local-pack series carry a market; organic series do not.
            $table->foreignUlid('market_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lane');

            // Organic series.
            $table->unsignedInteger('rank')->nullable();
            $table->string('ranking_url')->nullable();
            $table->json('serp_features')->nullable();

            // Local-pack grid series.
            $table->decimal('avg_rank', 6, 2)->nullable();
            $table->decimal('pct_top3', 5, 4)->nullable();
            $table->decimal('coverage', 5, 4)->nullable();

            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['keyword_id', 'lane']);
            $table->index(['market_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_snapshots');
    }
};
