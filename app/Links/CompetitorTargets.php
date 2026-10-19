<?php

namespace App\Links;

use App\Backlinks\BacklinkSyncer;
use App\Enums\CompetitorClass;
use App\KeywordGenerator\Beatability\CompetitorClassifier;
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
     * @return list<array{domain: string, appearances: int}> strongest first
     */
    public function for(Site $site, int $max = 10): array
    {
        $host = BacklinkSyncer::target((string) $site->domain_url);
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
                    foreach (is_array($point->top_results) ? $point->top_results : [] as $item) {
                        // One competitor per business: the registrable domain (m.yelp.com and yelp.com are one).
                        $domain = CompetitorClassifier::registrable((string) preg_replace('/^www\./i', '', $item['domain']));
                        if ($domain === '' || $domain === CompetitorClassifier::registrable($host) || ! self::inCountry($domain)) {
                            continue;
                        }
                        if ($ours !== null && $item['position'] > $ours) {
                            continue; // only who is above us
                        }
                        if (self::isPlatform($domain) || $this->classifier->classify($domain) !== CompetitorClass::LocalCompetitor) {
                            continue;
                        }
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
            $out[] = ['domain' => (string) $domain, 'appearances' => $n];
        }
        usort($out, fn (array $a, array $b): int => [$b['appearances'], $a['domain']] <=> [$a['appearances'], $b['domain']]);

        return array_slice($out, 0, max(1, $max));
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
