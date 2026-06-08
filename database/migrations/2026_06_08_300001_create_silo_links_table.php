<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('silo_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('from_silo_id')->constrained('silos')->cascadeOnDelete();
            $table->foreignUlid('to_silo_id')->constrained('silos')->cascadeOnDelete();
            // Intra-silo links (pillar<->cluster, siblings) are derivable; this
            // table persists the controlled cross-silo links.
            $table->string('relation')->default('cross_silo');
            $table->timestamps();

            $table->unique(['from_silo_id', 'to_silo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('silo_links');
    }
};
