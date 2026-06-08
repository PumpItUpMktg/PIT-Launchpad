<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_id')->nullable()->constrained()->nullOnDelete();
            $table->text('prompt')->nullable();
            $table->string('provider')->default('fal');
            $table->string('status')->default('queued');
            $table->string('r2_key')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('timeout')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_jobs');
    }
};
