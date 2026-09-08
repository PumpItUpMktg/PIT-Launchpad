<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\RankingState;
use App\Integrations\Analytics\PageTrafficProvider;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\FreshnessStamp;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Markets wall read-model: one {@see MarketCard} per GBP-anchored {@see Location} (UI "Market"),
 * ~a dozen per tenant — a ten-thousand-foot "is this market working" for every market at once. Each card
 * rolls up the market's own page plus every town page beneath it (`contents.location_id` for the hub,
 * `contents.parent_location_id` for the towns — the persisted assignment, never recomputed).
 *
 * CHEAP by construction (standing rule 2 — no per-card query, no HTTP in the render path): the whole wall
 * is a handful of batched aggregates computed ONCE and distributed in PHP —
 *  - ONE {@see gscRollup} over `gsc_url_daily` (56-day window, current-vs-prior split for the trend),
 *  - ONE {@see TierProgression::forSite} call with `withLinks:false` (the size-tier built/served math,
 *    skipping the full-site link-graph build — inbound links live on the market detail, per the relay),
 *  - ONE reviews aggregate, ONE citations aggregate, ONE page-index-verdict map, ONE `MAX(date)` GSC
 *    stamp, and cache-only GA4 reads ({@see PageTrafficProvider::sessionsCached} — never a live call).
 *
 * The absent-state rule (rule 7) is the spine: a market with no positioned page reads
 * {@see RankingState::NotTracked}, unwarmed GA4 reads not-tracked (null, never 0), a location with no
 * `place_id` gets no GBP link (not a broken one), and GSC/GA4 each carry their OWN freshness stamp with
 * their OWN cadence — daily vs weekly, never averaged. There is deliberately NO conversions tile: the
 * `conversions` table has no per-page/per-market attribution, so an honest per-market number does not
 * exist (it would have to be invented). A HELD market ({@see Location::$publish_held}) carries tier
 * counts + proof but no metric row — unpublished pages have nothing to report.
 */
class MarketCards
{
    /** Trailing metric window (days); the trend compares this window to the prior one of the same length. */
    private const WINDOW_DAYS = 28;

    public function __construct(
        private readonly TierProgression $tiers,
        private readonly PageTrafficProvider $traffic,
    ) {}

    /** @return list<MarketCard> one card per active Location, name-ordered */
    public function forSite(Site $site): array
    {
        $siteId = (string) $site->id;
        $domain = $site->domain_url;

        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->orderBy('name')->get();

        $content = $this->clusterContent($siteId);
        $hubs = $content->filter(fn (Content $c): bool => $c->location_id !== null)->keyBy(fn (Content $c): string => (string) $c->location_id);
        $towns = $content
            ->filter(fn (Content $c): bool => $c->location_id === null && $c->parent_location_id !== null && $c->primary_service_id === null)
            ->groupBy(fn (Content $c): string => (string) $c->parent_location_id);

        $gsc = $this->gscRollup($siteId);
        $verdicts = $this->verdicts($siteId);
        $bands = collect($this->tiers->forSite($site, withLinks: false))->keyBy('id');
        $reviews = $this->reviewAgg($siteId);
        $citations = $this->citationAgg($siteId);

        $gscStamp = FreshnessStamp::for($this->gscMaxDate($siteId), 86_400, noun: 'search data');
        $ga4Connected = $this->traffic->connected($site);
        $ga4Stamp = FreshnessStamp::for(
            $ga4Connected ? $this->traffic->lastWarmedAt($site) : null,
            604_800, // weekly warm cadence
            noun: 'sessions',
        );

        $cards = [];
        foreach ($locations as $location) {
            $cards[] = $this->card(
                $site, $location, $domain,
                $hubs->get((string) $location->id),
                $towns->get((string) $location->id) ?? collect(),
                $gsc, $verdicts, $bands->get((string) $location->id),
                $reviews[(string) $location->id] ?? null,
                $citations[(string) $location->id] ?? null,
                $gscStamp, $ga4Connected, $ga4Stamp,
            );
        }

        return $cards;
    }

    /**
     * @param  Collection<int, Content>  $townPages
     * @param  array<string, array{impr: int, clicks: int, prior_impr: int, prior_clicks: int, impr_pos: int, posw: float}>  $gsc
     * @param  array<string, string>  $verdicts
     * @param  array<string, mixed>|null  $band
     * @param  array{count: int, avg: float, latest: ?string}|null  $review
     * @param  array{count: int, latest: ?string}|null  $citation
     */
    private function card(
        Site $site, Location $location, ?string $domain, ?Content $hub, Collection $townPages,
        array $gsc, array $verdicts, ?array $band, ?array $review, ?array $citation,
        FreshnessStamp $gscStamp, bool $ga4Connected, FreshnessStamp $ga4Stamp,
    ): MarketCard {
        $cluster = $hub !== null ? $townPages->prepend($hub) : $townPages;
        $published = $cluster->filter(fn (Content $c): bool => $c->status === ContentStatus::Published);
        $held = (bool) $location->publish_held;

        ['city' => $city, 'state' => $state] = $location->cityState();
        $state = trim($state) !== '' ? trim($state) : null;

        // Metric row + ranking distribution are omitted for a held market (nothing published to report).
        $impr = $clicks = $priorImpr = $priorClicks = 0;
        $top3 = $pageOne = $beyond = 0;
        $positioned = false;
        $sessions = null;
        $anyWarmed = false;

        if (! $held) {
            foreach ($published as $page) {
                $stat = $gsc[$this->pathFor($domain, $page)] ?? null;
                if ($stat !== null) {
                    $impr += $stat['impr'];
                    $clicks += $stat['clicks'];
                    $priorImpr += $stat['prior_impr'];
                    $priorClicks += $stat['prior_clicks'];
                    if ($stat['impr_pos'] > 0) {
                        $positioned = true;
                        $pos = $stat['posw'] / $stat['impr_pos'];
                        match (true) {
                            $pos <= 3.0 => $top3++,
                            $pos <= 10.0 => $pageOne++,
                            default => $beyond++,
                        };
                    }
                }

                if ($ga4Connected) {
                    $s = $this->traffic->sessionsCachedState($site, $this->pathFor($domain, $page));
                    $anyWarmed = $anyWarmed || $s['warmed'];
                    if ($s['sessions'] !== null) {
                        $sessions = (int) $sessions + $s['sessions'];
                    }
                }
            }

            // Connected but nothing warmed yet → not_tracked (null), never a fabricated 0.
            if ($ga4Connected && ! $anyWarmed) {
                $sessions = null;
            }
        }

        $hasGsc = $impr > 0 || $clicks > 0 || $positioned;

        return new MarketCard(
            id: (string) $location->id,
            name: $state !== null && $city !== '' ? "{$city}, {$state}" : ($city !== '' ? $city : trim((string) $location->name)),
            county: null, // no county-name field on Location (only the home_county_geoid FIPS) — omitted, never a raw FIPS
            state: $state,
            marketPageUrl: $hub !== null && $hub->status === ContentStatus::Published ? PublicUrl::forContent($domain, $hub) : null,
            marketPagePosition: $this->hubPosition($domain, $hub, $gsc),
            marketPageIndexState: $this->indexState($domain, $hub, $gsc, $verdicts),
            gbpUrl: $this->gbpUrl($location),
            townsWithPages: (int) ($band['built'] ?? 0),
            townsTotal: (int) ($band['served'] ?? 0),
            sizeTiers: $this->sizeTiers($band),
            held: $held,
            draftedPages: $cluster->reject(fn (Content $c): bool => $c->status === ContentStatus::Published)->count(),
            countyMismatch: $this->countyMismatch($location),
            impressions: $held || ! $hasGsc ? null : $impr,
            impressionsDelta: $held || ! $hasGsc ? null : $impr - $priorImpr,
            clicks: $held || ! $hasGsc ? null : $clicks,
            clicksDelta: $held || ! $hasGsc ? null : $clicks - $priorClicks,
            sessions: $held ? null : $sessions,
            rankingState: (! $held && $positioned) ? RankingState::Ranked : RankingState::NotTracked,
            rankTop3: $top3,
            rankPageOne: $pageOne,
            rankBeyond: $beyond,
            reviewsCount: $review !== null ? $review['count'] : null,
            reviewsAvg: $review !== null ? round($review['avg'], 1) : null,
            citationsLive: $citation !== null ? $citation['count'] : null,
            jobsCount: null, // jobs tie to JobCity, not Location — no cheap per-market source (not_tracked)
            gscFreshness: $held ? null : $gscStamp,
            ga4Freshness: $held ? null : $ga4Stamp,
            proofFreshness: $this->proofStamp($review, $citation),
        );
    }

    /** All Location-type pages for the site in one query (hub + town rows), for the cluster grouping. */
    private function clusterContent(string $siteId): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->get(['id', 'title', 'slug', 'status', 'location_id', 'parent_location_id', 'primary_service_id']);
    }

    /**
     * ONE aggregate over the 56-day gsc_url_daily window, keyed by normalized path: current-window
     * impressions/clicks + the prior window (for the 28-day trend) + the blended-position numerator/
     * denominator for the CURRENT window (Σ position×impressions / Σ impressions-on-positioned-rows).
     *
     * @return array<string, array{impr: int, clicks: int, prior_impr: int, prior_clicks: int, impr_pos: int, posw: float}>
     */
    private function gscRollup(string $siteId): array
    {
        $curStart = Carbon::now()->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $priorStart = Carbon::now()->subDays(self::WINDOW_DAYS * 2 - 1)->toDateString();

        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $siteId)
            ->where('date', '>=', $priorStart)
            ->selectRaw('url,
                SUM(CASE WHEN date >= ? THEN impressions ELSE 0 END) AS impr,
                SUM(CASE WHEN date >= ? THEN clicks ELSE 0 END) AS clicks,
                SUM(CASE WHEN date <  ? THEN impressions ELSE 0 END) AS prior_impr,
                SUM(CASE WHEN date <  ? THEN clicks ELSE 0 END) AS prior_clicks,
                SUM(CASE WHEN date >= ? AND position IS NOT NULL THEN impressions ELSE 0 END) AS impr_pos,
                SUM(CASE WHEN date >= ? AND position IS NOT NULL THEN position * impressions ELSE 0 END) AS posw',
                [$curStart, $curStart, $curStart, $curStart, $curStart, $curStart])
            ->groupBy('url')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $path = UrlNormalizer::path((string) parse_url((string) $row->url, PHP_URL_PATH));
            $out[$path] ??= ['impr' => 0, 'clicks' => 0, 'prior_impr' => 0, 'prior_clicks' => 0, 'impr_pos' => 0, 'posw' => 0.0];
            $out[$path]['impr'] += (int) $row->impr;
            $out[$path]['clicks'] += (int) $row->clicks;
            $out[$path]['prior_impr'] += (int) $row->prior_impr;
            $out[$path]['prior_clicks'] += (int) $row->prior_clicks;
            $out[$path]['impr_pos'] += (int) $row->impr_pos;
            $out[$path]['posw'] += (float) $row->posw;
        }

        return $out;
    }

    /** The most recent GSC day covered for the site — the daily freshness stamp's source (no stored marker). */
    private function gscMaxDate(string $siteId): ?Carbon
    {
        $max = DB::table('gsc_url_daily')->where('site_id', $siteId)->max('date');

        return $max !== null ? Carbon::parse((string) $max) : null;
    }

    /** @return array<string, string> url_normalized => index_verdict */
    private function verdicts(string $siteId): array
    {
        return DB::table('page_index_states')
            ->where('site_id', $siteId)
            ->pluck('index_verdict', 'url_normalized')
            ->map(fn ($v): string => (string) $v)
            ->all();
    }

    /**
     * Published-review count + average rating + latest review date, per location, in one query.
     *
     * @return array<string, array{count: int, avg: float, latest: ?string}>
     */
    private function reviewAgg(string $siteId): array
    {
        $rows = DB::table('reviews')
            ->where('site_id', $siteId)
            ->where('status', 'published')
            ->whereNotNull('location_id')
            ->groupBy('location_id')
            ->selectRaw('location_id, COUNT(*) AS c, AVG(rating) AS a, MAX(reviewed_at) AS latest')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->location_id] = ['count' => (int) $row->c, 'avg' => (float) $row->a, 'latest' => $row->latest !== null ? (string) $row->latest : null];
        }

        return $out;
    }

    /**
     * Listed-citation count (present — match OR mismatch) + latest scan, per location, in one query.
     * A single card number; PR 4's detail panel splits matching vs differing.
     *
     * @return array<string, array{count: int, latest: ?string}>
     */
    private function citationAgg(string $siteId): array
    {
        $rows = DB::table('citation_statuses')
            ->where('site_id', $siteId)
            ->whereIn('presence', ['present_match', 'present_mismatch'])
            ->groupBy('location_id')
            ->selectRaw('location_id, COUNT(*) AS c, MAX(updated_at) AS latest')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->location_id] = ['count' => (int) $row->c, 'latest' => $row->latest !== null ? (string) $row->latest : null];
        }

        return $out;
    }

    /**
     * The four size-tier cells (major/large/medium/small) — always all four, defaulting to 0/0 so an
     * absent tier reads "0 of 0" rather than vanishing. Sourced from the TierProgression bands.
     *
     * @param  array<string, mixed>|null  $band
     * @return list<array{tier: string, label: string, built: int, served: int}>
     */
    private function sizeTiers(?array $band): array
    {
        $byTier = collect($band['tiers'] ?? [])->keyBy('tier');
        $labels = ['major' => 'Major', 'large' => 'Large', 'medium' => 'Medium', 'small' => 'Small'];

        $cells = [];
        foreach ($labels as $tier => $label) {
            $b = $byTier->get($tier);
            $cells[] = ['tier' => $tier, 'label' => $label, 'built' => (int) ($b['built'] ?? 0), 'served' => (int) ($b['served'] ?? 0)];
        }

        return $cells;
    }

    /** The GBP maps link built from the stored Place ID, or null when there is no place_id (no broken link). */
    private function gbpUrl(Location $location): ?string
    {
        $placeId = trim((string) $location->place_id);

        return $placeId !== '' ? 'https://www.google.com/maps/place/?q=place_id:'.rawurlencode($placeId) : null;
    }

    /** The Spring City defect surfaced on the card: a home county the location doesn't actually serve. */
    private function countyMismatch(Location $location): ?string
    {
        $home = trim((string) $location->home_county_geoid);
        $served = array_map('strval', (array) $location->county_geoids);
        if ($home === '' || in_array($home, $served, true)) {
            return null;
        }

        return 'Home county not in its served counties';
    }

    /** The hub page's blended GSC position, or null (not built / no positioned impressions). */
    private function hubPosition(?string $domain, ?Content $hub, array $gsc): ?int
    {
        if ($hub === null || $hub->status !== ContentStatus::Published) {
            return null;
        }
        $stat = $gsc[$this->pathFor($domain, $hub)] ?? null;

        return $stat !== null && $stat['impr_pos'] > 0 ? (int) round($stat['posw'] / $stat['impr_pos']) : null;
    }

    /**
     * The hub page's three-state index verdict: indexed (a PASS verdict OR earned GSC impressions),
     * not_indexed (a verdict exists but isn't PASS), or unchecked (no verdict, never inspected) — a
     * negative is never inferred from an absent verdict.
     *
     * @param  array<string, string>  $verdicts
     */
    private function indexState(?string $domain, ?Content $hub, array $gsc, array $verdicts): string
    {
        if ($hub === null || $hub->status !== ContentStatus::Published) {
            return 'unchecked';
        }
        $url = PublicUrl::forContent($domain, $hub);
        $verdict = $url !== null ? ($verdicts[UrlNormalizer::url($url)] ?? null) : null;
        $stat = $gsc[$this->pathFor($domain, $hub)] ?? null;
        $inGoogle = $stat !== null && $stat['impr'] > 0;

        return match (true) {
            $verdict === 'PASS' || $inGoogle => 'indexed',
            $verdict !== null => 'not_indexed',
            default => 'unchecked',
        };
    }

    /**
     * The proof-row stamp: "as of {latest review/citation date}", or null when there is no proof at all.
     * Proof has no fixed cadence, so a null interval keeps it quiet (an "as of" line, never a stale alarm).
     *
     * @param  array{count: int, avg: float, latest: ?string}|null  $review
     * @param  array{count: int, latest: ?string}|null  $citation
     */
    private function proofStamp(?array $review, ?array $citation): ?FreshnessStamp
    {
        $dates = array_filter([$review['latest'] ?? null, $citation['latest'] ?? null]);
        if ($dates === []) {
            return null;
        }

        $latest = collect($dates)->map(fn (string $d): Carbon => Carbon::parse($d))->max();

        return FreshnessStamp::for($latest, null, noun: 'proof');
    }

    /** Normalized page path for a content row, matching the gscRollup keying. */
    private function pathFor(?string $domain, Content $content): string
    {
        $url = PublicUrl::forContent($domain, $content);

        return UrlNormalizer::path($url ?? '/'.ltrim((string) $content->slug, '/'));
    }
}
