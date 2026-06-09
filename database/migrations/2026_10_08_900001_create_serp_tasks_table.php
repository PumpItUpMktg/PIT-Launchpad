<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serp_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Endpoint family (search_volume | organic | maps) + the DataForSEO
            // task id, the cache key the parsed result lands under, and the query
            // context. Used to dedupe in-flight tasks (no double-spend on refresh)
            // and to drive the tasks_ready ingest sweep.
            $table->string('function');
            $table->string('task_id')->nullable()->index();
            $table->string('cache_key')->index();
            $table->string('query');
            $table->unsignedBigInteger('location_code')->nullable();
            $table->string('language_code')->nullable();
            $table->string('location_coordinate')->nullable();
            $table->string('state')->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['function', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serp_tasks');
    }
};
