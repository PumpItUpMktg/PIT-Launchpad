<?php

namespace App\Roadmap;

use App\Backlinks\BacklinkSyncer;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\Backlinks\BacklinkProvider;
use App\Integrations\Places\PlaceReview;
use App\Integrations\Places\PlacesProvider;
use App\Jobs\PublishContent;
use App\KeywordGenerator\Beatability\CompetitorClassifier;
use App\Models\Content;
use App\Models\GbpProfile;
use App\Models\GbpReview;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Refresh the Google Business Profiles the office roadmap compares (§ Roadmap): the tenant's own offices,
 * and the map-pack competitors the geo grid keeps finding beside each office — the top N per office by how
 * many grid cells they hold in the latest scan of every office × keyword. One Places call per place; one
 * backlink-summary call per competitor whose domain is known. A place Places cannot resolve keeps its old
 * row. Nothing here contacts anyone.
 */
final class CompetitorProfiler
{
    public function __construct(
        private readonly PlacesProvider $places,
        private readonly BacklinkProvider $backlinks,
    ) {}

    /**
     * `unresolved_places` names every place Places returned nothing for — its name as the grid (or the office
     * record) knew it, whether it is one of our offices or a map-pack competitor, and the office it was
     * seen beside — so a closed, merged, or re-keyed listing can be found rather than counted.
     *
     * `reviews` counts the Google reviews stored for the offices (their own listings only — a competitor's
     * reviews are never kept), replaced whole per office so a review Google no longer returns drops.
     *
     * @return array{offices: int, competitors: int, profiled: int, unresolved: int, reviews: int, unresolved_places: list<array{place_id: string, name: string, role: string, beside: string}>}
     */
    public function refresh(Site $site): array
    {
        $perOffice = max(1, (int) config('launchpad.roadmap.competitors_per_office', 10));
        $offices = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->gbpBacked()->whereNotNull('place_id')->get();

        $wanted = []; // place_id => ['name' => …, 'domain' => …, 'location_id' => …, 'beside' => office name]
        foreach ($offices as $office) {
            $wanted[(string) $office->place_id] = ['name' => (string) $office->name, 'domain' => null, 'location_id' => (string) $office->id, 'beside' => (string) $office->name];
        }
        foreach ($offices as $office) {
            foreach (array_slice(self::packCompetitors($site, $office), 0, $perOffice, true) as $placeId => $c) {
                $wanted[$placeId] ??= ['name' => $c['name'], 'domain' => $c['domain'], 'location_id' => null, 'beside' => (string) $office->name];
            }
        }

        $profiled = $reviews = 0;
        $unresolved = [];
        $now = now();
        foreach ($wanted as $placeId => $meta) {
            $profile = $this->places->profile($placeId);
            if ($profile === null) {
                $unresolved[] = [
                    'place_id' => (string) $placeId,
                    'name' => $meta['name'],
                    'role' => $meta['location_id'] === null ? 'competitor' : 'office',
                    'beside' => $meta['beside'],
                ];

                continue;
            }
            $domain = $meta['domain'] ?? self::domainOf($profile->website);
            [$referring, $authority] = $domain !== null && $meta['location_id'] === null ? $this->backlinkSummary($domain) : [null, null];

            GbpProfile::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                ['site_id' => $site->id, 'place_id' => $placeId],
                [
                    'location_id' => $meta['location_id'],
                    'name' => $profile->name !== '' ? $profile->name : $meta['name'],
                    'domain' => $domain,
                    'rating' => $profile->rating,
                    'ratings_total' => $profile->ratingsTotal,
                    'types' => $profile->types,
                    'primary_type' => $profile->primaryType(),
                    'photo_count' => $profile->photoCount,
                    'business_status' => $profile->businessStatus,
                    'lat' => $profile->lat,
                    'lng' => $profile->lng,
                    'website' => $profile->website,
                    'referring_domains' => $referring,
                    'authority' => $authority,
                    'refreshed_at' => $now,
                ],
            );
            $profiled++;
            if ($meta['location_id'] !== null) {
                $reviews += $this->storeReviews($site, $meta['location_id'], (string) $placeId, $profile->reviews, $now);
            }
        }

        return [
            'offices' => $offices->count(),
            'competitors' => count($wanted) - $offices->count(),
            'profiled' => $profiled,
            'unresolved' => count($unresolved),
            'reviews' => $reviews,
            'unresolved_places' => $unresolved,
        ];
    }

    /**
     * Queue a re-push of every live location page (office hubs and their town pages) so the header rating
     * and the Google reviews section carry what this refresh found. Returns how many were queued.
     */
    public function repushLocationPages(Site $site): int
    {
        $ids = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->pluck('id')->all();
        foreach ($ids as $id) {
            PublishContent::dispatch((string) $id);
        }

        return count($ids);
    }

    /**
     * Replace an office's stored Google reviews with what Places returned this time.
     *
     * @param  list<PlaceReview>  $reviews
     */
    private function storeReviews(Site $site, string $locationId, string $placeId, array $reviews, Carbon $now): int
    {
        GbpReview::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('location_id', $locationId)->delete();
        foreach ($reviews as $rv) {
            GbpReview::withoutGlobalScope(SiteScope::class)->create([
                'site_id' => $site->id,
                'location_id' => $locationId,
                'place_id' => $placeId,
                'author_name' => $rv->authorName !== '' ? $rv->authorName : 'A Google user',
                'author_url' => $rv->authorUrl,
                'rating' => $rv->rating,
                'text' => $rv->text,
                'relative_time' => $rv->relativeTime,
                'reviewed_at' => $rv->time === null ? null : Carbon::createFromTimestamp($rv->time),
                'refreshed_at' => $now,
            ]);
        }

        return count($reviews);
    }

    /**
     * The map-pack competitors beside an office across the latest complete scan of each of its keywords:
     * place_id => {name, domain, cells}, most cells first.
     *
     * @return array<string, array{name: string, domain: string|null, cells: int}>
     */
    public static function packCompetitors(Site $site, Location $office): array
    {
        $latest = GeoGridScan::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('location_id', $office->id)
            ->whereIn('status', ['complete', 'partial'])->whereNotNull('scanned_at')
            ->orderByDesc('scanned_at')->get(['id', 'keyword_id'])
            ->unique('keyword_id')->pluck('id')->all();
        if ($latest === []) {
            return [];
        }
        $out = [];
        foreach (GeoGridPoint::withoutGlobalScope(SiteScope::class)->whereIn('scan_id', $latest)->get(['competitors']) as $point) {
            foreach (is_array($point->competitors) ? $point->competitors : [] as $c) {
                $placeId = is_string($c['place_id'] ?? null) ? $c['place_id'] : '';
                if ($placeId === '' || $placeId === (string) $office->place_id) {
                    continue;
                }
                $domain = is_string($c['domain'] ?? null) && $c['domain'] !== '' ? CompetitorClassifier::registrable((string) preg_replace('/^www\./i', '', $c['domain'])) : null;
                $out[$placeId] ??= ['name' => $c['name'], 'domain' => $domain, 'cells' => 0];
                $out[$placeId]['cells']++;
                $out[$placeId]['domain'] ??= $domain;
            }
        }
        uasort($out, fn (array $a, array $b): int => [$b['cells'], $a['name']] <=> [$a['cells'], $b['name']]);

        return $out;
    }

    /** @return array{int|null, int|null} */
    private function backlinkSummary(string $domain): array
    {
        try {
            $s = $this->backlinks->summary($domain);

            return [$s->referringDomains, $s->rank];
        } catch (Throwable) {
            return [null, null];
        }
    }

    public static function domainOf(?string $website): ?string
    {
        if ($website === null || trim($website) === '') {
            return null;
        }
        $host = BacklinkSyncer::target($website);

        return $host !== '' ? CompetitorClassifier::registrable($host) : null;
    }
}
