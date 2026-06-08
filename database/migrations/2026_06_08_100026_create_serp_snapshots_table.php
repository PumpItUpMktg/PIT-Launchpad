<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serp_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('query');
            $table->timestamp('captured_at')->nullable();
            $table->json('competitor_analysis')->nullable();
            $table->json('diff')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serp_snapshots');
    }
};
