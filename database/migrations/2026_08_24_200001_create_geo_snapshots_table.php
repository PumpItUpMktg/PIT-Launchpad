<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->ulid('geo_prompt_id')->index();       // deferred-FK style
            $table->string('engine', 32);                 // claude | perplexity | ...
            $table->boolean('cited')->default(false);     // was the brand named / its domain cited
            $table->unsignedSmallInteger('position')->nullable(); // rank among recommended businesses, if any
            $table->string('sentiment', 16)->nullable();  // positive | neutral | negative | absent
            $table->json('competitors')->nullable();      // competitor names/domains the answer cited
            $table->text('answer_excerpt')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['site_id', 'geo_prompt_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_snapshots');
    }
};
