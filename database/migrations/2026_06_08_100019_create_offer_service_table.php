<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_service', function (Blueprint $table) {
            $table->foreignUlid('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['offer_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_service');
    }
};
