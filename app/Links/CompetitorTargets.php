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
        foreach ($keywords as $keyword) {
            foreach (TownRankScan::MODES as $mode) {
                $scanId = TownRankScan::withoutGlobalScope(SiteScope::class)
                    ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                    ->orderByDesc('scanned_at')->value('id');
                if ($scanId === null) {
                    continue;
                }
                $points = TownRankPoint::withoutGlobalScope(SiteScope::class)->where('scan_id', $scanId)->get(['rank', 'top_results']);
                foreach ($points as $point) {
                    $ours = $point->rank;
                    foreach (is_array($point->top_results) ? $point->top_results : [] as $item) {
                        $domain = strtolower((string) preg_replace('/^www\./i', '', $item['domain']));
                        if ($domain === '' || $domain === $host) {
                            continue;
                        }
                        if ($ours !== null && $item['position'] > $ours) {
                            continue; // only who is above us
                        }
                        if (self::isPlatform($domain) || $this->classifier->classify($domain) !== CompetitorClass::LocalCompetitor) {
                            continue;
                        }
                        $counts[$domain] = ($counts[$domain] ?? 0) + 1;
                    }
                }
            }
        }

        $out = [];
        foreach ($counts as $domain => $n) {
            $out[] = ['domain' => (string) $domain, 'appearances' => $n];
        }
        usort($out, fn (array $a, array $b): int => [$b['appearances'], $a['domain']] <=> [$a['appearances'], $b['domain']]);

        return array_slice($out, 0, max(1, $max));
    }

    public static function isPlatform(string $domain): bool
    {
        $parts = explode('.', $domain);
        $stem = count($parts) >= 2 ? $parts[count($parts) - 2] : $parts[0];

        return in_array($stem, self::PLATFORMS, true);
    }
}
