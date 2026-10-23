<?php

namespace App\Links;

use App\Enums\CompetitorClass;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Integrations\Backlinks\BacklinkProvider;
use App\Integrations\Serp\SerpProvider;
use App\KeywordGenerator\Beatability\CompetitorClassifier;
use App\Models\LinkProspect;
use App\Models\LinkProspectRun;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Str;
use Throwable;

/**
 * Find the local link opportunities for a tenant (§ Links): sponsorships with a sponsors page, memberships
 * whose directories link out, the dealer / installer locators of the brands it carries, and the job and
 * training boards that list employer partners. One SERP search per lane-targeted query
 * ({@see LocalOpportunityQueries}); every organic result is reduced to its registrable domain, national
 * noise and platforms are dropped with the link prospector's own rules, the page is classified into a
 * lane by what it says about itself, and each kept domain gets ONE backlink-summary call for authority.
 * Stored as {@see LinkProspect} rows (kind = the lane) beside the competitor-link prospects — operator
 * status and notes survive a re-run; only the index fields refresh. Never contacts anyone, never submits
 * anything, never spends a cent beyond the searches.
 */
final class LocalOpportunityFinder
{
    /** Backlink-summary calls per run — authority for the strongest candidates, bounded. */
    public const AUTHORITY_LOOKUPS = 80;

    private const LANE_WORDS = [
        'sponsorship' => ['sponsor', 'sponsorship', 'donat', 'fundrais', 'league', 'booster', '5k', 'run-', 'golf-outing', 'gala'],
        'membership' => ['member', 'chamber', 'association', 'directory', 'join'],
        'dealer_locator' => ['dealer', 'installer', 'locator', 'where-to-buy', 'find-a-', 'contractor-locator', 'pro-locator', 'certified'],
        'job_board' => ['career', 'job', 'employ', 'apprentice', 'workforce', 'hiring', 'trade-school', 'training'],
        'press' => ['news', 'patch', 'gazette', 'times', 'journal', 'press', 'herald', 'courier', 'tribune', 'submit-a-story', 'news-tip', 'press-release', 'daily', 'weekly'],
    ];

    /**
     * Press is local or it is nothing: national and state-wide outlets, and the networks whose town pages
     * sit on one national domain, are dropped by name — and any press domain that turns up for offices in
     * more than one state is dropped by the data. Two states means a metro or regional outlet, not a town's.
     */
    private const NATIONAL_PRESS = [
        'nj.com', 'patch.com', 'nytimes.com', 'nypost.com', 'nydailynews.com', 'washingtonpost.com', 'usatoday.com', 'apnews.com', 'reuters.com',
        'cnn.com', 'foxnews.com', 'nbcnews.com', 'cbsnews.com', 'abcnews.go.com', 'msn.com', 'yahoo.com', 'news.google.com', 'bing.com',
        'inquirer.com', 'philly.com', 'baltimoresun.com', 'newsday.com', 'tapinto.net', 'wikipedia.org', 'wikimedia.org',
    ];

    public function __construct(
        private readonly LocalOpportunityQueries $queries,
        private readonly SerpProvider $serp,
        private readonly BacklinkProvider $backlinks,
        private readonly CompetitorClassifier $classifier,
    ) {}

    /**
     * @return array{queries: int, returned: int, kept: int, new: int, refreshed: int, dropped_national: int, dropped_platform: int, by_lane: array<string, int>, status: string, error: string|null}
     */
    public function run(Site $site): array
    {
        $plan = $this->queries->for($site);
        $own = CompetitorClassifier::registrable((string) preg_replace('/^www\./i', '', strtolower((string) parse_url((string) $site->domain_url, PHP_URL_HOST))));
        $now = now();

        $candidates = [];   // domain => {lane, url, query, location_id, offices, locality, hits}
        $returned = $droppedNational = $droppedPlatform = 0;
        try {
            foreach ($plan as $q) {
                $set = $this->serp->results($q['query']);
                foreach ($set->results as $r) {
                    $returned++;
                    $domain = CompetitorClassifier::registrable((string) preg_replace('/^www\./i', '', strtolower($r->domain)));
                    if ($domain === '' || $domain === $own) {
                        continue;
                    }
                    if (CompetitorTargets::isPlatform($domain)) {
                        $droppedPlatform++;

                        continue;
                    }
                    $class = $this->classifier->classify($domain);
                    if ($class === CompetitorClass::NationalBigBox || $class === CompetitorClass::AggregatorDirectory) {
                        $droppedNational++;

                        continue;
                    }
                    $lane = $this->lane($r->url, $q['lane']);
                    if ($lane === LinkProspectKind::Press && self::isNationalPress($domain)) {
                        $droppedNational++;

                        continue;
                    }
                    $locality = $this->locality($r->url, $q['office']);
                    $score = $locality * 10 + max(0, 20 - $r->position);
                    if (! isset($candidates[$domain]) || $score > $candidates[$domain]['score']) {
                        $prev = $candidates[$domain] ?? null;
                        $candidates[$domain] = [
                            'lane' => $lane, 'url' => $r->url, 'query' => $q['query'], 'location_id' => $q['location_id'],
                            'locality' => $locality, 'score' => $score,
                            'offices' => $prev['offices'] ?? [], 'hits' => ($prev['hits'] ?? 0),
                        ];
                    }
                    $candidates[$domain]['hits']++;
                    if (! in_array($q['office'], $candidates[$domain]['offices'], true)) {
                        $candidates[$domain]['offices'][] = $q['office'];
                    }
                }
            }
        } catch (Throwable $e) {
            $this->record($site, ['status' => LinkProspectRun::FAILED, 'error' => Str::limit($e->getMessage(), 500), 'queries' => count($plan), 'returned' => $returned]);
            throw $e;
        }

        // Press is local or it is nothing: an outlet that came up for offices in more than one state is a
        // metro or regional paper, not a town's — dropped as national.
        foreach ($candidates as $domain => $c) {
            if ($c['lane'] === LinkProspectKind::Press && count(self::statesOf($c['offices'])) > 1) {
                unset($candidates[$domain]);
                $droppedNational++;
            }
        }

        // Authority for the strongest candidates (bounded), then store.
        uasort($candidates, fn (array $a, array $b): int => [$b['hits'], $b['score']] <=> [$a['hits'], $a['score']]);
        $new = $refreshed = 0;
        $byLane = [];
        $lookups = 0;
        foreach ($candidates as $domain => $c) {
            $rank = null;
            if ($lookups < self::AUTHORITY_LOOKUPS) {
                $lookups++;
                try {
                    $rank = $this->backlinks->summary($domain)->rank;
                } catch (Throwable) {
                    $rank = null;
                }
            }
            $existing = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('domain', $domain)->first();
            $attrs = [
                'kind' => $c['lane']->value, 'url' => $c['url'], 'query' => $c['query'], 'location_id' => $c['location_id'],
                'offices' => $c['offices'], 'locality' => $c['locality'], 'found_at' => $now,
                'competitors' => $existing !== null ? $existing->competitors : [],
                'competitor_count' => $existing !== null ? (int) $existing->competitor_count : 0,
            ];
            if ($rank !== null) {
                $attrs['rank'] = $rank;
            }
            if ($existing === null) {
                LinkProspect::withoutGlobalScope(SiteScope::class)->create(array_merge($attrs, [
                    'site_id' => $site->id, 'domain' => $domain, 'status' => LinkProspectStatus::New->value, 'first_seen' => $now->toDateString(),
                ]));
                $new++;
            } else {
                // A domain the competitor intersect already holds keeps its kind from that pass unless it is
                // a plain local site — the lane is the more useful label.
                if ($existing->kind !== LinkProspectKind::Local && ! $existing->kind->isLane()) {
                    unset($attrs['kind']);
                }
                $existing->forceFill($attrs)->save();
                $refreshed++;
            }
            $byLane[$c['lane']->value] = ($byLane[$c['lane']->value] ?? 0) + 1;
        }

        $result = [
            'queries' => count($plan), 'returned' => $returned, 'kept' => count($candidates), 'new' => $new, 'refreshed' => $refreshed,
            'dropped_national' => $droppedNational, 'dropped_platform' => $droppedPlatform, 'by_lane' => $byLane,
            'status' => LinkProspectRun::COMPLETE, 'error' => null,
        ];
        $this->record($site, $result);

        return $result;
    }

    /** The lane a result page belongs to: what the URL says about itself, else the lane of the search that found it. */
    public static function isNationalPress(string $domain): bool
    {
        foreach (self::NATIONAL_PRESS as $n) {
            if ($domain === $n || str_ends_with($domain, '.'.$n)) {
                return true;
            }
        }

        return false;
    }

    /** The states behind office labels ("Montclair, NJ" → NJ; a bare "NJ" → NJ). @param list<string> $offices @return list<string> */
    private static function statesOf(array $offices): array
    {
        $states = [];
        foreach ($offices as $o) {
            $parts = array_map('trim', explode(',', $o));
            $st = strtoupper((string) end($parts));
            if (preg_match('/^[A-Z]{2}$/', $st) === 1) {
                $states[$st] = true;
            }
        }

        return array_keys($states);
    }

    private function lane(string $url, LinkProspectKind $fromQuery): LinkProspectKind
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH)).' '.strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::LANE_WORDS as $lane => $words) {
            foreach ($words as $w) {
                if (str_contains($path, $w)) {
                    return LinkProspectKind::from($lane);
                }
            }
        }

        return $fromQuery;
    }

    /** 0–3: the office's city, then county / state, named in the page URL. */
    private function locality(string $url, string $office): int
    {
        $u = strtolower($url);
        $parts = array_values(array_filter(array_map('trim', explode(',', strtolower($office)))));
        $score = 0;
        if (isset($parts[0]) && str_contains($u, Str::slug($parts[0]))) {
            $score += 2;
        }
        if (isset($parts[1]) && preg_match('/[\/.-]'.preg_quote($parts[1], '/').'[\/.-]/', $u) === 1) {
            $score += 1;
        }

        return min(3, $score);
    }

    /** @param  array<string, mixed>  $r */
    private function record(Site $site, array $r): void
    {
        LinkProspectRun::withoutGlobalScope(SiteScope::class)->create([
            'site_id' => $site->id,
            'scope' => 'opportunities',
            'status' => $r['status'] ?? LinkProspectRun::COMPLETE,
            'targets' => [], 'excluded' => [],
            'queries' => (int) ($r['queries'] ?? 0),
            'returned' => (int) ($r['returned'] ?? 0), 'kept' => (int) ($r['kept'] ?? 0),
            'new' => (int) ($r['new'] ?? 0), 'refreshed' => (int) ($r['refreshed'] ?? 0), 'pruned' => 0,
            'dropped_spam' => (int) ($r['dropped_platform'] ?? 0), 'dropped_rank' => (int) ($r['dropped_national'] ?? 0),
            'error' => $r['error'] ?? null, 'ran_at' => now(),
        ]);
    }
}
