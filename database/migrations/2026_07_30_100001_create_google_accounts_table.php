<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The single shared platform Google grant — the "one email" the operator connects once and that
     * every client adds as a user on their GSC property + GA4 property. This is the §9 PLATFORM-secret
     * tier (like app_key / anthropic_key), NOT a per-tenant credential: one refreshing OAuth token,
     * reused across all sites. It lives OUT of the tenant `connections` table on purpose — the §9 gate,
     * masker, rotator and staleness sweep all iterate per-site connections; a platform token has no
     * business there. Each Site keeps only a NON-secret pointer (which GSC/GA4 property to query).
     *
     * Singleton: only ever one row (the first/newest grant wins in the service).
     */
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The connected Google account's email/display, for the operator surface ("Connected as …").
            // Best-effort — null until/unless a userinfo read populates it; never load-bearing.
            $table->string('label')->nullable();
            // access/refresh tokens + expiry + scopes — stored via the encrypted:array cast, no plaintext.
            $table->text('credentials')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status')->default('connected');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
