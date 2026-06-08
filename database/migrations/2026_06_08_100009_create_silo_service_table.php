<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('silo_service', function (Blueprint $table) {
            $table->foreignUlid('silo_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['silo_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('silo_service');
    }
};
