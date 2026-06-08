<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role')->default('operator');
            $table->timestamps();

            $table->unique(['user_id', 'account_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
