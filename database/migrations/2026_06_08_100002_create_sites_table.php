<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->string('brand_name');
            $table->string('legal_name')->nullable();
            $table->string('dba')->nullable();
            $table->string('tagline')->nullable();
            $table->string('domain_url')->nullable();
            $table->json('slug_conventions')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
