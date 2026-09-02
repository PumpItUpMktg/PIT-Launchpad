<?php

namespace App\Reviews\Requests;

use App\Models\ReviewRequest;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Str;

/**
 * Single-use review-request tokens (§6). The DB stores only the SHA-256 hash (never the plaintext), mirroring
 * the platform's device-token pattern: a leaked table can't be used to submit reviews. The plaintext is handed
 * to the outbound email once; a reminder ROTATES the token (fresh plaintext, new hash) so old links die and
 * the DB still holds no plaintext. Resolution drops the site scope — the token itself carries the tenant.
 */
final class ReviewTokens
{
    /** @return array{0: string, 1: string} [plaintext, hash] */
    public function generate(): array
    {
        $plain = Str::random(48);

        return [$plain, hash('sha256', $plain)];
    }

    /** Resolve a request by its plaintext token, or null if no such live token. */
    public function find(string $plain): ?ReviewRequest
    {
        if (trim($plain) === '') {
            return null;
        }

        return ReviewRequest::query()->withoutGlobalScope(SiteScope::class)
            ->where('token', hash('sha256', $plain))->first();
    }

    /** Issue a fresh token onto a request (for a reminder) and return the plaintext to deliver. */
    public function rotate(ReviewRequest $request): string
    {
        [$plain, $hash] = $this->generate();
        $request->forceFill(['token' => $hash])->save();

        return $plain;
    }
}
