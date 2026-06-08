<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keywords', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('silo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('query');
            $table->string('intent')->nullable();
            $table->string('source')->default('seed');
            $table->unsignedInteger('volume')->nullable();
            $table->unsignedInteger('difficulty')->nullable();
            $table->decimal('opportunity_score', 8, 4)->nullable();
            $table->decimal('beatability', 8, 4)->nullable();
            // FK to contents is deferred (circular dependency); see Keyword::targetContent().
            $table->ulid('target_content_id')->nullable()->index();
            $table->string('status')->default('candidate');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};
