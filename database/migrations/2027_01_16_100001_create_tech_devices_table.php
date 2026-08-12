<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Capture §5 — the tech's capture device. A tech authenticates the PWA with a magic link / 6-digit
 * code and receives a LONG-LIVED device token (no WordPress account, no password): tech churn is one
 * revoked token. Site-scoped — a device belongs to one tenant's crew. Only hashes are stored: the login
 * code (`login_code_hash`, short-lived) and the device token (`token_hash`, revoked via `revoked_at`),
 * never the plaintext. `last_active_at` is the liveness signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tech_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('token_hash')->nullable()->index();   // sha256 of the long-lived device token
            $table->string('login_code_hash')->nullable();        // sha256 of the 6-digit login code
            $table->timestamp('login_code_expires_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tech_devices');
    }
};
