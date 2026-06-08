<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_asset_market', function (Blueprint $table) {
            $table->foreignUlid('media_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('market_id')->constrained()->cascadeOnDelete();
            $table->primary(['media_asset_id', 'market_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_asset_market');
    }
};
