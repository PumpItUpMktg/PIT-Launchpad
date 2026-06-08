<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_media', function (Blueprint $table) {
            $table->foreignUlid('content_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_asset_id')->constrained()->cascadeOnDelete();
            $table->primary(['content_id', 'media_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_media');
    }
};
