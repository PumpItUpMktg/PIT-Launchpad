<?php

namespace App\Links\Outreach;

use App\Enums\ClientDecision;
use App\Models\LinkProspect;
use App\Models\OutreachEmail;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * The guardrails on link outreach (§ Links), checked at the moment of sending — never earlier, never
 * skipped. Every line is a hard stop:
 *  - outreach is switched on for the platform;
 *  - the tenant has a sender address on its own domain (mail from anywhere else is refused);
 *  - the client marked the prospect Interested (the client decides — no blast);
 *  - a contact address is known;
 *  - the prospect is still open (not won, declined or dismissed);
 *  - the tenant's monthly cap (≤ the platform ceiling) has room — queued sends hold a place;
 *  - a pitch goes once; a follow-up goes once, only after the pitch, only after the waiting period.
 */
final class OutreachGuard
{
    public function check(Site $site, LinkProspect $prospect, string $kind, ?Carbon $now = null): GuardResult
    {
        $now ??= now();
        $cap = self::capFor($site);
        $used = $this->usedThisMonth($site, $now);
        $sender = Sender::for($site);
        $pitch = $this->latest($prospect, OutreachEmail::PITCH);
        $followUp = $this->latest($prospect, OutreachEmail::FOLLOW_UP);
        $waitDays = max(1, (int) config('launchpad.links.outreach.follow_up_after_days', 5));

        $checks = [
            ['key' => 'enabled', 'ok' => (bool) config('launchpad.links.outreach.enabled', true), 'text' => 'Outreach is switched off for the platform.'],
            ['key' => 'sender', 'ok' => $sender !== null, 'text' => 'No sender on the tenant\'s own domain — set one on the Business step (mail never goes out from anywhere else).'],
            ['key' => 'decision', 'ok' => $prospect->client_decision === ClientDecision::Interested, 'text' => 'The client has not marked this prospect Interested.'],
            ['key' => 'contact', 'ok' => trim((string) $prospect->contact_email) !== '' && filter_var(trim((string) $prospect->contact_email), FILTER_VALIDATE_EMAIL) !== false, 'text' => 'No contact address — find one or enter it.'],
            ['key' => 'open', 'ok' => $prospect->status->isOpen(), 'text' => 'The prospect is closed ('.$prospect->status->label().').'],
            ['key' => 'cap', 'ok' => $used < $cap, 'text' => "The monthly cap is used up ({$used} of {$cap} this month)."],
        ];
        if ($kind === OutreachEmail::FOLLOW_UP) {
            $checks[] = ['key' => 'pitched', 'ok' => $pitch !== null, 'text' => 'No pitch has been sent yet.'];
            $checks[] = ['key' => 'once', 'ok' => $followUp === null, 'text' => 'The one follow-up has already been sent.'];
            $checks[] = ['key' => 'waited', 'ok' => $pitch !== null && $pitch->approved_at->copy()->addDays($waitDays)->lte($now),
                'text' => $pitch !== null ? 'Give the pitch '.$waitDays.' days — a follow-up may go from '.$pitch->approved_at->copy()->addDays($waitDays)->toFormattedDateString().'.' : 'No pitch has been sent yet.'];
        } else {
            $checks[] = ['key' => 'once', 'ok' => $pitch === null, 'text' => 'The pitch has already been sent — send the one follow-up instead.'];
        }

        return new GuardResult($checks, $used, $cap);
    }

    /** The tenant's cap, never above the platform ceiling, never below one. */
    public static function capFor(Site $site): int
    {
        $hard = max(1, (int) config('launchpad.links.outreach.hard_cap', 20));

        return max(1, min($hard, (int) ($site->outreach_monthly_cap ?? $hard)));
    }

    /** Emails approved this calendar month that the provider has not refused — queued ones hold their place. */
    public function usedThisMonth(Site $site, ?Carbon $now = null): int
    {
        $now ??= now();

        return OutreachEmail::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('status', '!=', OutreachEmail::FAILED)
            ->whereBetween('approved_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->count();
    }

    private function latest(LinkProspect $prospect, string $kind): ?OutreachEmail
    {
        return OutreachEmail::withoutGlobalScope(SiteScope::class)->where('link_prospect_id', $prospect->id)
            ->where('kind', $kind)->where('status', '!=', OutreachEmail::FAILED)->orderByDesc('approved_at')->first();
    }
}
