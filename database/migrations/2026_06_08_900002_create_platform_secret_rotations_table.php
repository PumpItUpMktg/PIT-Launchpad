<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_secret_rotations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // One attestation per platform secret (rotate once, gate satisfied
            // for all tenants thereafter).
            $table->string('platform_secret')->unique();
            $table->timestamp('rotated_at');
            $table->foreignUlid('rotated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_secret_rotations');
    }
};
