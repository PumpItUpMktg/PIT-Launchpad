<?php

namespace App\Links;

use App\Backlinks\BacklinkSyncer;
use App\Enums\CompetitorClass;
use App\KeywordGenerator\Beatability\CompetitorClassifier;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownPages\PriorityKeywords;

/**
 * The local competitors Town Rank keeps finding above the site (§ Links): across the priority keywords (every
 * tracked keyword when none is priority), both modes, the latest scan of each, the domains that outrank us
 * in each town. Aggregators, national chains and editorial sites are dropped — their links are not ours to
 * copy — and the rest are ranked by how many town results they beat us in. These are the targets the
 * competitor intersect asks the backlink index about.
 */
final class CompetitorTargets
{
    /**
     * Hosts that outrank a local business in a town result without being a competitor — a Facebook page,
     * a Google Business site, a site builder's shared host. An intersect over one of these asks the index
     * for every domain that links to Facebook; the classifier does not know them, so they are named here.
     */
    private const PLATFORMS = [
        'facebook', 'google', 'business', 'instagram', 'youtube', 'linkedin', 'x', 'twitter', 'tiktok',
        'pinterest', 'wikipedia', 'wixsite', 'godaddysites', 'squarespace', 'weebly', 'wordpress', 'blogspot',
        'bing', 'apple', 'yahoo', 'craigslist', 'indeed', 'glassdoor', 'reddit', 'quora',
    ];

    /** Town results in one scan needed before a competitor's footprint share means anything. */
    public const FOOTPRINT_SAMPLE = 20;

    /**
     * The domains the last call dropped as territory-wide, with the worst per-scan share — so the command
     * can say who was excluded and why.
     *
     * @var list<array{domain: string, appearances: int, share: float}>
     */
    public array $dropped = [];

    public function __construct(
        private readonly PriorityKeywords $priorities,
        private readonly CompetitorClassifier $classifier,
    ) {}

    /**
     * Map-pack competitors first (local businesses by definition, from the geo grid), then the organic
     * town-rank competitors that pass the footprint and country rules, up to `$max`.
     *
     * @return list<array{domain: string, appearances: int, source: string}> strongest first
     */
    public function for(Site $site, int $max = 10): array
    {
        $self = CompetitorClassifier::registrable(BacklinkSyncer::target((string) $site->domain_url));
        $mapPack = $this->mapPack($site, $self);
        $taken = array_column($mapPack, 'domain');
        $organic = array_values(array_filter($this->organic($site, $self), fn (array $t): bool => ! in_array($t['domain'], $taken, true)));

        return array_slice(array_merge($mapPack, $organic), 0, max(1, $max));
    }

    /**
     * The businesses in the local pack across the latest complete grid scan of every (office × keyword):
     * registrable domain, counted once per grid cell, strongest first. Older scans have no domain on their
     * competitor rows (it is kept from the scanner change on), so they simply contribute nothing.
     *
     * @return list<array{domain: string, appearances: int, source: string}>
     */
    private function mapPack(Site $site, string $self): array
    {
        $latest = GeoGridScan::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('status', 'complete')->whereNotNull('scanned_at')
            ->orderByDesc('scanned_at')->get(['id', 'location_id', 'keyword_id'])
            ->unique(fn (GeoGridScan $s): string => $s->location_id.'|'.$s->keyword_id)->pluck('id')->all();
        if ($latest === []) {
            return [];
        }
        $counts = [];
        foreach (GeoGridPoint::withoutGlobalScope(SiteScope::class)->whereIn('scan_id', $latest)->get(['competitors']) as $point) {
            $seen = [];
            foreach (is_array($point->competitors) ? $point->competitors : [] as $c) {
                $raw = $c['domain'] ?? null;
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                $domain = CompetitorClassifier::registrable((string) preg_replace('/^www\./i', '', $raw));
                // A chain's map-pack listing (Roto-Rooter, Mr. Rooter) is local in the pack and national in its
                // links — the classifier's lists still apply.
                if ($domain === '' || $domain === $self || isset($seen[$domain]) || self::isPlatform($domain) || ! self::inCountry($domain)
                    || $this->classifier->classify($domain) !== CompetitorClass::LocalCompetitor) {
                    continue;
                }
                $seen[$domain] = true;
                $counts[$domain] = ($counts[$domain] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($counts as $domain => $n) {
            $out[] = ['domain' => (string) $domain, 'appearances' => $n, 'source' => 'map_pack'];
        }
        usort($out, fn (array $a, array $b): int => [$b['appearances'], $a['domain']] <=> [$a['appearances'], $b['domain']]);

        return $out;
    }

    /**
     * The organic competitors above us in the town results (see the class note), strongest first.
     *
     * @return list<array{domain: string, appearances: int, source: string}>
     */
    private function organic(Site $site, string $self): array
    {
        $keywords = $this->priorities->for($site);
        if ($keywords === []) {
            $ids = TownRankScan::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->distinct()->pluck('keyword_id');
            $keywords = Keyword::withoutGlobalScope(SiteScope::class)->whereIn('id', $ids)->get()->all();
        }

        $counts = [];
        /** @var array<string, array<string, int>> $perScan  scan id => domain => appearances */
        $perScan = [];
        /** @var array<string, int> $scanSize */
        $scanSize = [];
        foreach ($keywords as $keyword) {
            foreach (TownRankScan::MODES as $mode) {
                $scanId = TownRankScan::withoutGlobalScope(SiteScope::class)
                    ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                    ->orderByDesc('scanned_at')->value('id');
                if ($scanId === null) {
                    continue;
                }
                $scanPoints = TownRankPoint::withoutGlobalScope(SiteScope::class)->where('scan_id', $scanId)->get(['rank', 'top_results']);
                $scanSize[$scanId] = $scanPoints->count();
                foreach ($scanPoints as $point) {
                    $ours = $point->rank;
                    $seen = []; // one count per town, however many of a domain's URLs sit in its top five
                    foreach (is_array($point->top_results) ? $point->top_results : [] as $item) {
                        // One competitor per business: the registrable domain (m.yelp.com and yelp.com are one).
                        $domain = CompetitorClassifier::registrable((string) preg_replace('/^www\./i', '', $item['domain']));
                        if ($domain === '' || $domain === $self || isset($seen[$domain]) || ! self::inCountry($domain)) {
                            continue;
                        }
                        if ($ours !== null && $item['position'] > $ours) {
                            continue; // only who is above us
                        }
                        if (self::isPlatform($domain) || $this->classifier->classify($domain) !== CompetitorClass::LocalCompetitor) {
                            continue;
                        }
                        $seen[$domain] = true;
                        $counts[$domain] = ($counts[$domain] ?? 0) + 1;
                        $perScan[$scanId][$domain] = ($perScan[$scanId][$domain] ?? 0) + 1;
                    }
                }
            }
        }

        // A local company's footprint is a county, not the territory: a domain above us in more than
        // `max_footprint` of the towns of ANY one scan (a Florida restoration site "winning" 1,307 town
        // results) is a national or junk site the lists do not know, and its links are not ours to copy.
        // Per scan, not across all of them — a site that owns one keyword everywhere still averages low
        // over five keywords. Judged only on a real sample: a scan of a handful of towns has no footprint.
        $cap = (float) config('launchpad.links.max_footprint', 0.5);
        $this->dropped = [];
        $out = [];
        foreach ($counts as $domain => $n) {
            $worst = 0.0;
            foreach ($perScan as $scanId => $domains) {
                $size = $scanSize[$scanId] ?? 0;
                if ($size >= self::FOOTPRINT_SAMPLE && isset($domains[$domain])) {
                    $worst = max($worst, $domains[$domain] / $size);
                }
            }
            if ($worst > $cap) {
                $this->dropped[] = ['domain' => (string) $domain, 'appearances' => $n, 'share' => round($worst, 2)];

                continue;
            }
            $out[] = ['domain' => (string) $domain, 'appearances' => $n, 'source' => 'organic'];
        }
        usort($out, fn (array $a, array $b): int => [$b['appearances'], $a['domain']] <=> [$a['appearances'], $b['domain']]);

        return $out;
    }

    /**
     * A country-code domain outside the tenant's country is not a local competitor — harrisplumbing.ca does
     * not serve New Jersey, however often it ranks for the phrase. Generic TLDs always pass.
     */
    public static function inCountry(string $domain): bool
    {
        $tld = (string) substr(strrchr($domain, '.') ?: '', 1);
        if (strlen($tld) !== 2) {
            return true;
        }
        $allowed = config('launchpad.links.country_tlds', ['us']);

        return in_array($tld, is_array($allowed) ? $allowed : ['us'], true);
    }

    public static function isPlatform(string $domain): bool
    {
        $parts = explode('.', $domain);
        $stem = count($parts) >= 2 ? $parts[count($parts) - 2] : $parts[0];

        return in_array($stem, self::PLATFORMS, true);
    }
}
