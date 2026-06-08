<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('geo_id')->nullable();
            $table->string('region')->nullable();
            $table->string('tier')->default('coverage');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('demographics')->nullable();
            $table->json('neighborhoods')->nullable();
            $table->text('local_nuances')->nullable();
            $table->boolean('is_covered')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('markets');
    }
};
