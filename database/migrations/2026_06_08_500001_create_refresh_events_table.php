<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_id')->constrained()->cascadeOnDelete();
            $table->string('trigger');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['content_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_events');
    }
};
