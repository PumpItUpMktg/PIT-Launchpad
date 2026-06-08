<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('scope')->nullable();
            $table->string('silo_role')->default('supporting');
            $table->string('gbp_service_type_id')->nullable();
            $table->string('pricing_posture')->nullable();
            $table->boolean('is_most_profitable')->default(false);
            $table->boolean('is_growth_priority')->default(false);
            $table->string('primary_cta_intent')->nullable();
            $table->string('geo_applicability')->default('all');
            $table->json('peak_months')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
