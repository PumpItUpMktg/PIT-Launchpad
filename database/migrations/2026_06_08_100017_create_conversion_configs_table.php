<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('primary_actions')->nullable();
            $table->json('tracked_numbers')->nullable();
            $table->json('lead_destination')->nullable();
            $table->json('forms')->nullable();
            $table->json('analytics_ids')->nullable();
            $table->string('booking_system')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_configs');
    }
};
