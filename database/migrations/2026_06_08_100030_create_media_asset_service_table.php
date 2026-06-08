<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_asset_service', function (Blueprint $table) {
            $table->foreignUlid('media_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['media_asset_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_asset_service');
    }
};
