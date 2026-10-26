<?php

namespace App\Locations\Sources;

use App\Integrations\Census\MunicipalityGazetteer;
use App\Integrations\Serp\SerpProvider;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The official local sources a TOWN page cites (§ Locations): the municipality's own website, the county's,
 * and the FEMA flood-map search for the town. Resolved once per town — one search each for the town and
 * the county (a county found once is reused across its towns), a government-domain rule on the result, a
 * live check before it is kept — and stored on the coverage area. Anything that fails the rule or the check
 * is left off rather than guessed: a town page cites two or three real sources or fewer, never a stray one.
 *
 * Government rule: a `.gov` or `.us` host always passes; a `.org` / `.com` / `.net` host passes only when it
 * carries the town's (or county's) name and is not a known non-government site (wiki, social, directories,
 * listings, news). Competitor directories, aggregators and pay-to-list sites never pass.
 */
final class TownSourceResolver
{
    public const MUNICIPALITY = 'municipality';

    public const COUNTY = 'county';

    public const FLOOD_MAP = 'flood_map';

    private const NEVER = ['wikipedia', 'wikimedia', 'wikidata', 'facebook', 'instagram', 'twitter', 'x.com', 'linkedin', 'youtube', 'yelp', 'patch.com', 'nj.com', 'niche.com', 'zillow', 'realtor', 'trulia', 'redfin', 'city-data', 'movoto', 'mapquest', 'tripadvisor', 'indeed', 'glassdoor', 'nextdoor', 'homeadvisor', 'angi', 'thumbtack', 'yellowpages', 'bbb.org', 'manta', 'chamberofcommerce.com', 'tapinto', 'usa.com', 'hometownlocator', 'census.gov', 'google.', 'bing.', 'apple.'];

    /** @var array<string, array{label: string, url: string}|null> county sources found this run, keyed by county geoid */
    private array $counties = [];

    public function __construct(
        private readonly SerpProvider $serp,
        private readonly MunicipalityGazetteer $gazetteer,
    ) {}

    /**
     * Resolve and store the town's sources. Returns what was kept.
     *
     * @return list<array{key: string, label: string, url: string, verified_at: string}>
     */
    public function resolve(CoverageArea $area): array
    {
        $town = trim((string) $area->name);
        $state = strtoupper(trim((string) $area->state));
        if ($town === '' || $state === '') {
            $area->forceFill(['local_sources' => [], 'local_sources_resolved_at' => now()])->save();

            return [];
        }
        $out = [];

        $muni = $this->find("{$town} {$state} official website", $town, [$town]);
        if ($muni !== null) {
            $out[] = ['key' => self::MUNICIPALITY, 'label' => "{$town}, {$state} — official municipal website", 'url' => $muni, 'verified_at' => now()->toIso8601String()];
        }

        $county = $this->countySource($area, $state);
        if ($county !== null) {
            $out[] = ['key' => self::COUNTY, 'label' => $county['label'], 'url' => $county['url'], 'verified_at' => now()->toIso8601String()];
        }

        $out[] = ['key' => self::FLOOD_MAP, 'label' => "FEMA flood map search for {$town}, {$state}", 'url' => 'https://msc.fema.gov/portal/search?AddressQuery='.rawurlencode("{$town}, {$state}"), 'verified_at' => now()->toIso8601String()];

        $area->forceFill(['local_sources' => $out, 'local_sources_resolved_at' => now()])->save();

        return $out;
    }

    /** @return array{label: string, url: string}|null */
    private function countySource(CoverageArea $area, string $state): ?array
    {
        $geo = trim((string) $area->geo_id);
        if (strlen($geo) < 5) {
            return null;
        }
        $countyGeo = substr($geo, 0, 5);
        if (array_key_exists($countyGeo, $this->counties)) {
            return $this->counties[$countyGeo];
        }
        // A sibling town in the same county already carries the county source — reuse it, no search.
        $sibling = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $area->site_id)
            ->where('geo_id', 'like', $countyGeo.'%')->whereNotNull('local_sources')->whereKeyNot($area->id)->get();
        foreach ($sibling as $s) {
            foreach ((array) $s->local_sources as $src) {
                if ($src['key'] === self::COUNTY) {
                    return $this->counties[$countyGeo] = ['label' => (string) $src['label'], 'url' => (string) $src['url']];
                }
            }
        }
        $name = $this->countyName($countyGeo);
        if ($name === null) {
            return $this->counties[$countyGeo] = null;
        }
        $url = $this->find("{$name} County {$state} official website", $name, [$name.' county', $name]);

        return $this->counties[$countyGeo] = $url !== null ? ['label' => "{$name} County, {$state} — official website", 'url' => $url] : null;
    }

    private function countyName(string $countyGeo): ?string
    {
        try {
            foreach ($this->gazetteer->countiesInState(substr($countyGeo, 0, 2)) as $county) {
                if ($county->geoId === $countyGeo) {
                    return trim((string) preg_replace('/\s+County$/i', '', $county->name));
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** One search; the first result that passes the government rule AND answers is the source. @param list<string> $names */
    private function find(string $query, string $name, array $names): ?string
    {
        try {
            $results = $this->serp->results($query)->results;
        } catch (Throwable) {
            return null;
        }
        foreach (array_slice($results, 0, 10) as $r) {
            $host = strtolower((string) parse_url($r->url, PHP_URL_HOST));
            if ($host === '' || ! self::isGovernment($host, $names)) {
                continue;
            }
            $url = self::homepage($r->url);
            if ($this->alive($url)) {
                return $url;
            }
        }

        return null;
    }

    /** @param list<string> $names the town's or county's names, any of which a non-.gov host must carry */
    public static function isGovernment(string $host, array $names): bool
    {
        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        foreach (self::NEVER as $bad) {
            if (str_contains($host, $bad)) {
                return false;
            }
        }
        if (str_ends_with($host, '.gov') || str_ends_with($host, '.us')) {
            return true;
        }
        if (preg_match('/\.(org|com|net)$/', $host) !== 1) {
            return false;
        }
        $bare = preg_replace('/[^a-z0-9]/', '', $host) ?? $host;
        foreach ($names as $n) {
            $slug = preg_replace('/[^a-z0-9]/', '', strtolower($n)) ?? '';
            if (strlen($slug) >= 4 && str_contains($bare, $slug)) {
                return true;
            }
        }

        return false;
    }

    /** The site's root — a search result deep in the site still cites the municipality's home page. */
    private static function homepage(string $url): string
    {
        $scheme = (string) (parse_url($url, PHP_URL_SCHEME) ?: 'https');
        $host = (string) parse_url($url, PHP_URL_HOST);

        return "{$scheme}://{$host}/";
    }

    private function alive(string $url): bool
    {
        try {
            $r = Http::timeout(8)->connectTimeout(5)->withUserAgent('Mozilla/5.0 (compatible; Launchpad/1.0)')->get($url);

            return $r->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** The sources a town page shows, in citing order. @return list<array{label: string, url: string}> */
    public static function forPage(?CoverageArea $area): array
    {
        if ($area === null || ! is_array($area->local_sources)) {
            return [];
        }
        $out = [];
        foreach ($area->local_sources as $s) {
            $url = trim($s['url']);
            $label = trim($s['label']);
            if ($url !== '' && $label !== '' && Str::startsWith($url, 'https://')) {
                $out[] = ['label' => $label, 'url' => $url];
            }
        }

        return array_slice($out, 0, 3);
    }
}
