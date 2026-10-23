<?php

namespace App\Client;

use App\Enums\ClientDecision;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Links\Outreach\OutreachGuard;
use App\Models\LinkProspect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;

/**
 * The client's view of link opportunities (§ Links, PR C): every open prospect the engine found for
 * their site, in plain words, with one decision per row — Interested or Pass. The client decides which
 * organisations get written to; nothing is emailed to a prospect they have not marked Interested, and
 * the monthly cap stays on the tenant. Honest framing: a local link is one of the signals search engines
 * read for who is established locally — never a promised ranking.
 */
class LinkOpportunities
{
    /**
     * @return array{
     *   to_review: list<array<string, mixed>>, interested: list<array<string, mixed>>, passed: list<array<string, mixed>>,
     *   totals: array{to_review: int, interested: int, passed: int, contacted: int, won: int},
     *   outreach: array{used: int, cap: int}
     * }
     */
    public function for(Site $site): array
    {
        $rows = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->whereNotIn('status', [LinkProspectStatus::Dismissed->value])
            ->orderByRaw('CASE WHEN client_decision IS NULL THEN 0 ELSE 1 END')->orderByDesc('locality')->orderByDesc('competitor_count')->orderBy('domain')
            ->get()->map(fn (LinkProspect $p): array => $this->row($p, $site));

        $toReview = $rows->filter(fn (array $r): bool => $r['decision'] === null && $r['open'])->values()->all();
        $interested = $rows->filter(fn (array $r): bool => $r['decision'] === ClientDecision::Interested->value)->values()->all();
        $passed = $rows->filter(fn (array $r): bool => $r['decision'] === ClientDecision::Pass->value)->values()->all();

        return [
            'to_review' => $toReview,
            'interested' => $interested,
            'passed' => $passed,
            'totals' => [
                'to_review' => count($toReview),
                'interested' => count($interested),
                'passed' => count($passed),
                'contacted' => count(array_filter($interested, fn (array $r): bool => $r['emails_sent'] > 0)),
                'won' => count(array_filter($interested, fn (array $r): bool => $r['status'] === LinkProspectStatus::Acquired->value)),
            ],
            'outreach' => ['used' => app(OutreachGuard::class)->usedThisMonth($site), 'cap' => OutreachGuard::capFor($site)],
        ];
    }

    /** Record the client's call. Only an open prospect of the client's own site; null clears the decision. */
    public function decide(Site $site, User $by, string $prospectId, ?ClientDecision $decision): bool
    {
        $prospect = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->find($prospectId);
        if ($prospect === null || ! $prospect->status->isOpen()) {
            return false;
        }
        $prospect->forceFill([
            'client_decision' => $decision,
            'client_decided_at' => $decision !== null ? now() : null,
            'client_decided_by' => $decision !== null ? $by->id : null,
        ])->save();

        return true;
    }

    /** @return array<string, mixed> */
    private function row(LinkProspect $p, Site $site): array
    {
        return [
            'id' => (string) $p->id,
            'domain' => (string) $p->domain,
            'url' => $p->url ?? 'https://'.$p->domain,
            'kind' => $p->kind->value,
            'kind_label' => $p->kind->label(),
            'what' => self::explain($p->kind, $site),
            'near' => $p->kind->isLane() ? ($p->offices !== null && $p->offices !== [] ? implode(', ', $p->offices) : 'state-wide') : null,
            'decision' => $p->client_decision?->value,
            'decided_at' => $p->client_decided_at,
            'status' => $p->status->value,
            'status_text' => self::statusText($p),
            'open' => $p->status->isOpen(),
            'emails_sent' => (int) $p->emails_sent,
            'last_emailed_at' => $p->last_emailed_at,
        ];
    }

    /** What saying yes means, in the client's words — including the money where there is some. */
    public static function explain(LinkProspectKind $kind, Site $site): string
    {
        $budget = '';
        if ($site->sponsorship_budget_min !== null || $site->sponsorship_budget_max !== null) {
            $min = $site->sponsorship_budget_min !== null ? '$'.number_format((int) $site->sponsorship_budget_min) : null;
            $max = $site->sponsorship_budget_max !== null ? '$'.number_format((int) $site->sponsorship_budget_max) : null;
            $budget = ' Your sponsorship range on file is '.($min !== null && $max !== null ? "{$min}–{$max}" : ($min ?? $max)).' a year.';
        }

        return match ($kind) {
            LinkProspectKind::Sponsorship => 'A local organisation that takes sponsors. Usually a yearly fee; sponsors are typically named on their website with a link, and often at events.'.$budget,
            LinkProspectKind::Membership => 'A chamber, association or business group with a member directory. Usually annual dues; members are listed with a website link.',
            LinkProspectKind::DealerLocator => 'A manufacturer or program with a dealer / installer locator. Being listed is normally free for businesses that carry the brand.',
            LinkProspectKind::JobBoard => 'A job or training board that lists employer partners. Normally free; you would post openings there and your profile links to your site.',
            LinkProspectKind::Directory => 'A directory that lists local businesses. We would ask to be listed, usually free.',
            LinkProspectKind::Editorial => 'A news, community or government site that already links to nearby companies. We would ask for a mention where it fits.',
            LinkProspectKind::National => 'A larger site that links to nearby companies. We would ask for a listing or a mention where it fits.',
            LinkProspectKind::Local => 'A local website that links to nearby companies but not to you yet. We would ask for a link where it fits.',
        };
    }

    private static function statusText(LinkProspect $p): string
    {
        return match ($p->status) {
            LinkProspectStatus::New => $p->client_decision === ClientDecision::Interested ? 'Not written to yet' : 'Waiting for your decision',
            LinkProspectStatus::Contacted => $p->last_emailed_at !== null ? 'We wrote to them '.$p->last_emailed_at->toFormattedDateString() : 'We have reached out',
            LinkProspectStatus::Replied => 'They replied — in conversation',
            LinkProspectStatus::Acquired => 'Done — they link to you',
            LinkProspectStatus::Declined => 'They declined',
            LinkProspectStatus::Dismissed => 'Set aside',
        };
    }
}
