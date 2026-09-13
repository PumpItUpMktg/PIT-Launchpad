<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-facing interview link (relay PR 1): a MULTI-USE, expiring, revocable, tenant-scoped token in the
 * review-capture pattern. Only the SHA-256 hash is stored — the plaintext is handed out once, in the link.
 * Unlike a review token it is not spent on submit: the client will not finish the interview in one sitting,
 * so the same link keeps resolving until it expires or the operator revokes it. `interview_id` is bound the
 * first time the client opens the link (the site's open in-progress interview — the same row the operator's
 * Setup step shows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_invites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('interview_id')->nullable()->index();
            $table->string('token', 64)->unique();           // sha-256 hex of the plaintext, never the plaintext
            $table->string('recipient_email')->nullable();   // who the link was addressed to (informational)
            $table->ulid('issued_by')->nullable();           // soft ref to users
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_invites');
    }
};
