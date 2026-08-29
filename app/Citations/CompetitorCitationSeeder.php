<?php

namespace App\Citations;

use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\CitationFoundDomain;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seeds directory candidates by reverse-looking-up where a COMPETITOR is listed (§ Citations, PR8).
 *
 * A competitor a client competes with is, by definition, in the same market and trade — so the directories
 * that competitor appears in are exactly the directories the client should consider. This runs brand-anchored
 * SERP queries for the competitor, and every result domain that ISN'T already in the catalog (and isn't the
 * competitor's own site or a non-directory like a search engine / social network) is persisted into the SAME
 * `citation_found_domains` candidate path the scan uses — so {@see DirectoryCandidateHarvester} surfaces it for
 * promotion with no new data path. Catalog growth, sourced from the competition.
 */
final class CompetitorCitationSeeder
{
    /** Domains that are never citation directories — don't seed them as candidates. */
    private const NON_DIRECTORY = [
        'google.com', 'bing.com', 'yahoo.com', 'duckduckgo.com',
        'facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com',
        'youtube.com', 'tiktok.com', 'pinterest.com', 'wikipedia.org', 'reddit.com',
    ];

    public function __construct(private readonly DataForSeoClient $dfs) {}

    /**
     * Reverse-look-up a competitor's directory footprint for a location and persist the unmatched domains as
     * candidates. Returns a tally.
     *
     * @return array{seen: int, matched: int, candidates: int}
     */
    public function seed(Location $location, string $competitorName, ?string $competitorDomain = null): array
    {
        $competitorName = trim($competitorName);
        if ($competitorName === '') {
            return ['seen' => 0, 'matched' => 0, 'candidates' => 0];
        }

        $ownDomain = $competitorDomain !== null ? $this->normalizeDomain($competitorDomain) : '';
        $directories = Directory::query()->where('is_active', true)->get();
        $now = Carbon::now();

        $seen = 0;
        $matched = 0;
        $candidates = 0;
        foreach ($this->runQueries($this->buildQueries($location, $competitorName, $competitorDomain)) as $domain => $url) {
            $seen++;
            if ($domain === $ownDomain || $this->isNonDirectory($domain)) {
                continue;
            }
            if ($this->matchDirectory($domain, $directories) !== null) {
                $matched++;

                continue; // already in the catalog — nothing to seed
            }

            // Unmatched: persist as a candidate on the SAME path the scan/harvester use.
            CitationFoundDomain::query()->updateOrCreate(
                ['site_id' => $location->site_id, 'location_id' => $location->id, 'domain' => $domain],
                ['directory_id' => null, 'found_url' => $url, 'last_seen_at' => $now],
            );
            CitationFoundDomain::query()
                ->where('site_id', $location->site_id)->where('location_id', $location->id)->where('domain', $domain)
                ->whereNull('first_seen_at')->update(['first_seen_at' => $now]);
            $candidates++;
        }

        return ['seen' => $seen, 'matched' => $matched, 'candidates' => $candidates];
    }

    /**
     * Brand-anchored queries for the competitor: name, name + the location's city, name + domain.
     *
     * @return list<string>
     */
    private function buildQueries(Location $location, string $competitorName, ?string $competitorDomain): array
    {
        $profile = LocationNapProfile::query()->where('location_id', $location->id)->first();
        $city = trim((string) ($profile->city ?? ''));

        $queries = [
            $competitorName,
            $city !== '' ? $competitorName.' '.$city : '',
            $competitorDomain !== null && trim($competitorDomain) !== '' ? $competitorName.' '.trim($competitorDomain) : '',
        ];

        return array_values(array_unique(array_filter($queries, fn (string $q): bool => trim($q) !== '')));
    }

    /**
     * @param  list<string>  $queries
     * @return array<string, string> normalized domain => first-seen url
     */
    private function runQueries(array $queries): array
    {
        $locationCode = (int) config('services.dataforseo.location_code', 2840);
        $language = (string) config('services.dataforseo.language_code', 'en');
        $depth = (int) config('services.dataforseo.serp_depth', 20);

        $out = [];
        foreach ($queries as $query) {
            foreach ($this->dfs->liveOrganic($query, $locationCode, $language, $depth) as $row) {
                $domain = $this->normalizeDomain((string) $row['domain']);
                if ($domain !== '' && ! isset($out[$domain])) {
                    $out[$domain] = (string) $row['url'];
                }
            }
        }

        return $out;
    }

    private function isNonDirectory(string $domain): bool
    {
        foreach (self::NON_DIRECTORY as $blocked) {
            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Directory>  $directories
     */
    private function matchDirectory(string $domain, Collection $directories): ?Directory
    {
        foreach ($directories as $dir) {
            $d = $this->normalizeDomain((string) $dir->domain);
            if ($d !== '' && ($domain === $d || str_ends_with($domain, '.'.$d))) {
                return $dir;
            }
        }

        return null;
    }

    private function normalizeDomain(string $domain): string
    {
        $d = mb_strtolower(trim($domain));
        $d = preg_replace('#^https?://#', '', $d) ?? $d;
        $d = preg_replace('#^www\.#', '', $d) ?? $d;

        return rtrim((string) strtok($d, '/'), '.');
    }
}
