<?php

namespace App\Guided;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * The LIVE boards read model — published pages only, grouped the way the business is shaped.
 * Three boards (separate pages in the Live nav group):
 *
 *  - LOCATIONS: one group per physical Location — its landing page (the location_id pin) leading,
 *    a towns roll-up, its assigned town pages (parent_location_id), and its city-service pages
 *    when earned. A single-location site collapses to one group; orphan town pages (no assignment)
 *    surface in their own band with the assign-location picker.
 *  - SERVICES: hub + spoke cards, hubs first.
 *  - CORE: home + standard pages.
 *
 * Membership is a STATE-DRIVEN QUERY (status = published) — a page "moves" between Grow and Live
 * by changing state, never by data migration, so regenerate/take-down flows it back automatically.
 */
class LiveBoards
{
    public function __construct(private readonly LiveMetrics $metrics) {}

    /**
     * @return array{groups: list<array{location: array<string, mixed>|null, location_card: array<string, mixed>|null, rollup: array<string, mixed>, towns: list<array<string, mixed>>, city_services: list<array<string, mixed>>}>, orphans: list<array<string, mixed>>, location_options: array<string, string>}
     */
    public function locations(Site $site): array
    {
        $published = $this->published($site);
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->get();

        $groups = [];
        foreach ($locations as $location) {
            $landing = $published->first(fn (Content $c) => (string) $c->location_id === (string) $location->id);
            $towns = $published
                ->filter(fn (Content $c) => $c->page_type === PageType::Location
                    && $c->location_id === null
                    && (string) $c->parent_location_id === (string) $location->id
                    && $c->primary_service_id === null)
                ->values();
            // City-service pages (earned per pair): location-parented pages with a service subject.
            $cityServices = $published
                ->filter(fn (Content $c) => (string) $c->parent_location_id === (string) $location->id
                    && $c->primary_service_id !== null)
                ->values();

            // A location with nothing live yet still shows (the group is the business shape).
            $groups[] = [
                'location' => [
                    'id' => (string) $location->id,
                    'name' => trim((string) $location->name),
                    'city' => $location->cityState()['city'],
                    'storefront' => (bool) $location->is_storefront,
                    'served' => collect($location->served_towns ?? [])
                        ->map(fn ($t) => trim((string) ($t['name'] ?? '')))
                        ->filter()->values()->all(),
                ],
                'location_card' => $landing !== null ? $this->card($landing, $site) : null,
                'rollup' => $this->rollup($towns, $site),
                'towns' => $towns->map(fn (Content $c) => $this->card($c, $site))->all(),
                'city_services' => $cityServices->map(fn (Content $c) => $this->card($c, $site))->all(),
            ];
        }

        // Orphans: published town pages no location claims yet → the assign-location picker band.
        $orphans = $published
            ->filter(fn (Content $c) => $c->page_type === PageType::Location
                && $c->location_id === null
                && $c->parent_location_id === null)
            ->values()
            ->map(fn (Content $c) => $this->card($c, $site))
            ->all();

        return [
            'groups' => $groups,
            'orphans' => $orphans,
            'location_options' => $locations->mapWithKeys(fn (Location $l) => [(string) $l->id => trim((string) $l->name) !== '' ? (string) $l->name : ($l->cityState()['city'] ?: (string) $l->id)])->all(),
        ];
    }

    /** @return list<array<string, mixed>> hubs first, then spokes alphabetically */
    public function services(Site $site): array
    {
        return $this->published($site)
            ->filter(fn (Content $c) => in_array($c->page_type, [PageType::Hub, PageType::Service, PageType::Pillar, PageType::Cluster], true))
            ->sortBy([
                fn (Content $a, Content $b) => ($b->page_type === PageType::Hub ? 1 : 0) <=> ($a->page_type === PageType::Hub ? 1 : 0),
                fn (Content $a, Content $b) => strcasecmp((string) $a->title, (string) $b->title),
            ])
            ->values()
            ->map(fn (Content $c) => $this->card($c, $site))
            ->all();
    }

    /** @return list<array<string, mixed>> home first, then standard pages alphabetically */
    public function core(Site $site): array
    {
        return $this->published($site)
            ->filter(fn (Content $c) => in_array($c->page_type, [PageType::Home, PageType::Utility], true))
            ->sortBy([
                fn (Content $a, Content $b) => ($b->page_type === PageType::Home ? 1 : 0) <=> ($a->page_type === PageType::Home ? 1 : 0),
                fn (Content $a, Content $b) => strcasecmp((string) $a->title, (string) $b->title),
            ])
            ->values()
            ->map(fn (Content $c) => $this->card($c, $site))
            ->all();
    }

    /** The site-level source connections (chips) — resolved once per render. */
    public function sources(Site $site): array
    {
        return $this->metrics->sources($site);
    }

    /**
     * One published page as a Live card: identity + dates + the tracking block.
     *
     * @return array<string, mixed>
     */
    private function card(Content $content, Site $site): array
    {
        $home = is_string($site->domain_url) && trim((string) $site->domain_url) !== ''
            ? rtrim((string) $site->domain_url, '/').'/'
            : '/';

        return [
            'id' => (string) $content->id,
            'title' => (string) $content->title,
            'type' => $content->page_type->value ?? 'page',
            'url' => $home.ltrim((string) $content->slug, '/'),
            'published_at' => $content->published_at?->toDateString(),
            'days_live' => $content->published_at !== null ? (int) $content->published_at->diffInDays(now()) : null,
            'locked' => (bool) $content->locked,
            'metrics' => $this->metrics->for($content),
        ];
    }

    /**
     * The towns roll-up strip for a location group — computed from the SAME cards the group shows,
     * so it can never disagree with them. Position average only over towns that have one.
     *
     * @param  Collection<int, Content>  $towns
     * @return array{towns_live: int, avg_rank: ?int, impressions: ?int, clicks: ?int}
     */
    private function rollup(Collection $towns, Site $site): array
    {
        $blocks = $towns->map(fn (Content $c) => $this->metrics->for($c));

        $ranks = $blocks->pluck('position.rank')->filter(fn ($r) => $r !== null);
        $impressions = $blocks->pluck('gsc.impressions')->filter(fn ($i) => $i !== null);
        $clicks = $blocks->pluck('gsc.clicks')->filter(fn ($c) => $c !== null);

        return [
            'towns_live' => $towns->count(),
            'avg_rank' => $ranks->isNotEmpty() ? (int) round($ranks->avg()) : null,
            'impressions' => $impressions->isNotEmpty() ? (int) $impressions->sum() : null,
            'clicks' => $clicks->isNotEmpty() ? (int) $clicks->sum() : null,
        ];
    }

    /**
     * @return Collection<int, Content>
     */
    private function published(Site $site): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->get();
    }
}
