<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_vitals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            // The materialized Content row when the URL maps to one (deferred-FK style — indexed, no constraint).
            $table->ulid('content_id')->nullable()->index();
            $table->string('url', 2048);
            $table->string('url_normalized', 2048);
            $table->string('strategy', 16)->default('mobile');   // mobile | desktop
            $table->unsignedTinyInteger('performance_score')->nullable();   // 0–100
            $table->unsignedInteger('lcp_ms')->nullable();                  // Largest Contentful Paint
            $table->decimal('cls', 6, 3)->nullable();                       // Cumulative Layout Shift
            $table->unsignedInteger('inp_ms')->nullable();                  // Interaction to Next Paint
            $table->timestamp('measured_at')->nullable();
            $table->timestamps();

            // One durable reading per URL per site (strategy carried on the row; mobile is the default sweep).
            $table->unique(['site_id', 'url_normalized']);
            $table->index(['site_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_vitals');
    }
};
