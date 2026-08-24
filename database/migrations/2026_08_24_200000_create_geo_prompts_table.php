<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_prompts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');                       // the operator-curated question to test
            $table->string('label')->nullable();          // optional short label for the board
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['site_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_prompts');
    }
};
