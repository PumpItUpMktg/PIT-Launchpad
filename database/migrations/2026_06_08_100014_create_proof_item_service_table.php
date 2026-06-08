<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proof_item_service', function (Blueprint $table) {
            $table->foreignUlid('proof_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['proof_item_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_item_service');
    }
};
