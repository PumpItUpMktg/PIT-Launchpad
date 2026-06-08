<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_problems', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->string('phrase');
            $table->string('intent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_problems');
    }
};
