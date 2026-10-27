<?php

namespace App\Links;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\LinkProspect;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The outbound side of a WON link relationship (§ Links): an office hub names the organisations the
 * business sponsors, belongs to, or is listed with — with a link — because that is the honest half of the
 * relationship and what a sponsor expects to see. Only prospects marked Won (link acquired) in the
 * sponsorship, membership and dealer-locator lanes; a local one on the office that found it, a state-wide
 * one (no office) on every hub. Never press (coverage is not a relationship) or competitor-link rows.
 */
final class CommunityLinks
{
    private const MAX = 8;

    /** @return list<array{role: string, label: string, url: string}> */
    public function forLocation(Site $site, Location $location): array
    {
        $rows = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('status', LinkProspectStatus::Acquired->value)
            ->whereIn('kind', [LinkProspectKind::Sponsorship->value, LinkProspectKind::Membership->value, LinkProspectKind::DealerLocator->value])
            ->where(fn ($q) => $q->where('location_id', $location->id)->orWhereNull('location_id'))
            ->orderByRaw("CASE kind WHEN 'sponsorship' THEN 0 WHEN 'membership' THEN 1 ELSE 2 END")->orderBy('domain')
            ->limit(self::MAX)->get();

        $out = [];
        foreach ($rows as $p) {
            $name = trim((string) ($p->profile['name'] ?? ''));
            $label = $name !== '' ? $name : (string) $p->domain;
            // A sponsor or member links the organisation's front door; a dealer listing links the locator page itself.
            $url = $p->kind === LinkProspectKind::DealerLocator && trim((string) $p->url) !== '' ? (string) $p->url : 'https://'.$p->domain.'/';
            $out[] = ['role' => self::role($p->kind), 'label' => $label, 'url' => $url];
        }

        return $out;
    }

    /** The published hub page(s) a prospect's relationship shows on: its office's hub, or every hub when it has no office. */
    public function hubPagesFor(LinkProspect $prospect): array
    {
        $q = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $prospect->site_id)
            ->where('kind', ContentKind::Page->value)->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)->whereNotNull('location_id');
        if ($prospect->location_id !== null) {
            $q->where('location_id', $prospect->location_id);
        }

        return $q->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    /** Re-push the hub(s) a status change affects — only when the relationship is one the hub shows. */
    public function repushFor(LinkProspect $prospect): int
    {
        if (! in_array($prospect->kind, [LinkProspectKind::Sponsorship, LinkProspectKind::Membership, LinkProspectKind::DealerLocator], true)) {
            return 0;
        }
        $ids = $this->hubPagesFor($prospect);
        foreach ($ids as $id) {
            PublishContent::dispatch($id);
        }

        return count($ids);
    }

    public static function role(LinkProspectKind $kind): string
    {
        return match ($kind) {
            LinkProspectKind::Sponsorship => 'Proud sponsor of',
            LinkProspectKind::Membership => 'Member of',
            LinkProspectKind::DealerLocator => 'Listed installer with',
            default => 'Partner of',
        };
    }
}
