<?php

namespace App\Interview\Invites;

use App\Models\InterviewInvite;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Str;

/**
 * Client interview links (relay PR 1) — the review-capture token pattern ({@see \App\Reviews\Requests\ReviewTokens})
 * with three deliberate differences:
 *
 *  - MULTI-USE: resolving or opening the link never spends it. A client will not finish in one sitting, so the
 *    link stays live until it expires or the operator revokes it.
 *  - EXPIRING: `launchpad.interview_invite_ttl_days` (default 30) from issue — long enough that a client who picks
 *    it up next week is fine, short enough that a link in an old email doesn't stay live indefinitely.
 *  - REVOCABLE / RE-ISSUABLE: one live invite per site; issuing again revokes the previous link (a fresh
 *    plaintext, a new hash — the old link dies), and the operator can revoke outright.
 *
 * The DB holds only the SHA-256 hash. Resolution drops every site scope — the token carries the tenant — and
 * {@see bind()} then locks that tenant for the request via {@see CurrentSite}, exactly as the review page does.
 */
final class InterviewInvites
{
    /** @return array{0: string, 1: string} [plaintext, hash] */
    public function generate(): array
    {
        $plain = Str::random(48);

        return [$plain, hash('sha256', $plain)];
    }

    /** Issue a fresh link for a site, revoking any link that is still live. */
    public function issue(Site $site, ?string $recipientEmail = null, ?string $issuedBy = null, ?int $ttlDays = null): IssuedInvite
    {
        $this->revokeLive($site);

        [$plain, $hash] = $this->generate();
        $ttl = $ttlDays ?? self::ttlDays();

        $invite = new InterviewInvite;
        $invite->forceFill([
            'site_id' => $site->id,
            'token' => $hash,
            'recipient_email' => $recipientEmail !== null && trim($recipientEmail) !== '' ? trim($recipientEmail) : null,
            'issued_by' => $issuedBy,
            'issued_at' => now(),
            'expires_at' => now()->addDays(max(1, $ttl)),
            'open_count' => 0,
        ])->save();

        return new IssuedInvite($invite, $plain);
    }

    /** Resolve a link by its plaintext token, or null when no such token exists. Scope-free: the row carries the tenant. */
    public function find(string $plain): ?InterviewInvite
    {
        if (trim($plain) === '') {
            return null;
        }

        return InterviewInvite::query()->withoutGlobalScopes()->where('token', hash('sha256', $plain))->first();
    }

    /** The site's live invite, if any (scope-free). */
    public function live(Site $site): ?InterviewInvite
    {
        return InterviewInvite::query()->withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('issued_at')
            ->first();
    }

    /** Revoke one link outright (idempotent). */
    public function revoke(InterviewInvite $invite): InterviewInvite
    {
        if ($invite->revoked_at === null) {
            $invite->forceFill(['revoked_at' => now()])->save();
        }

        return $invite;
    }

    /** Revoke every live link for a site. Returns how many were revoked. */
    public function revokeLive(Site $site): int
    {
        $count = 0;
        foreach (InterviewInvite::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('revoked_at')->get() as $invite) {
            $this->revoke($invite);
            $count++;
        }

        return $count;
    }

    /**
     * Bind the link's tenant for this request and record the open. Never spends the link. Returns the Site,
     * resolved without any scope — the only place the public host learns which tenant it is serving.
     */
    public function bind(InterviewInvite $invite): Site
    {
        CurrentSite::set((string) $invite->site_id);

        $invite->forceFill([
            'last_opened_at' => now(),
            'open_count' => $invite->open_count + 1,
        ])->save();

        return Site::query()->withoutGlobalScopes()->findOrFail($invite->site_id);
    }

    public static function ttlDays(): int
    {
        return (int) config('launchpad.interview_invite_ttl_days', 30);
    }
}
