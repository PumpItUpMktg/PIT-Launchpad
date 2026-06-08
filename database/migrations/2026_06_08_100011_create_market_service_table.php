<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_service', function (Blueprint $table) {
            $table->foreignUlid('market_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['market_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_service');
    }
};
