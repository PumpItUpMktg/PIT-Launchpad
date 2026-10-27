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

        $muni = $this->find("{$town} {$state} official website", fn (string $host, int $position): bool => self::isMunicipalHost($host, [$town]));
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
        $url = $this->find("{$name} County {$state} official website", fn (string $host, int $position): bool => self::isCountyHost($host, $name, $position));

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

    /** One search; the first result whose host passes the slot's rule AND whose https front door answers is the source. @param callable(string, int): bool $rule */
    private function find(string $query, callable $rule): ?string
    {
        try {
            $results = $this->serp->results($query)->results;
        } catch (Throwable) {
            return null;
        }
        foreach (array_slice($results, 0, 10) as $r) {
            $host = strtolower((string) parse_url($r->url, PHP_URL_HOST));
            if ($host === '' || ! $rule($host, (int) $r->position)) {
                continue;
            }
            $url = self::homepage($r->url);
            if ($this->alive($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * The MUNICIPALITY slot: the host must carry the town's name — on a .gov too, because a county portal or
     * the state's own site is a .gov as well and is not the town. Never a county portal, a state portal, or
     * any of the known non-government sites.
     *
     * @param  list<string>  $townNames
     */
    public static function isMunicipalHost(string $host, array $townNames): bool
    {
        $host = self::bareHost($host);
        if ($host === null || self::isStatePortal($host)) {
            return false;
        }
        if (preg_match('/\.(gov|us|org|com|net)$/', $host) !== 1) {
            return false;
        }
        $compact = preg_replace('/[^a-z0-9]/', '', $host) ?? $host;
        $carriesTown = false;
        foreach ($townNames as $n) {
            $slug = preg_replace('/[^a-z0-9]/', '', strtolower($n)) ?? '';
            if (strlen($slug) >= 4 && str_contains($compact, $slug)) {
                $carriesTown = true;
                break;
            }
        }
        if (! $carriesTown) {
            return false;
        }
        // A county portal that happens to carry the town's name ("Harford County" for a town called Harford) is still the county.
        $townIsCounty = array_filter($townNames, fn (string $n): bool => str_contains(strtolower($n), 'county')) !== [];

        return $townIsCounty || ! str_contains($compact, 'county');
    }

    /**
     * The COUNTY slot: a .gov / .us host that carries the county's name or the word "county" (or "co."), or —
     * because county portals often abbreviate ("norcopa.gov") — the TOP result of the county search when it
     * is a .gov / .us that is not a municipality's; or a .org / .com / .net host that carries BOTH the county's
     * name and the word "county" ("lehighcounty.org", never "lehightownship.com").
     */
    public static function isCountyHost(string $host, string $countyName, int $position = 99): bool
    {
        $host = self::bareHost($host);
        if ($host === null || self::isStatePortal($host)) {
            return false;
        }
        $compact = preg_replace('/[^a-z0-9]/', '', $host) ?? $host;
        if (preg_match('/(township|borough|village|twp|cityof|townof)/', $compact) === 1) {
            return false;
        }
        $slug = preg_replace('/[^a-z0-9]/', '', strtolower($countyName)) ?? '';
        $carriesName = strlen($slug) >= 4 && str_contains($compact, $slug);
        $saysCounty = str_contains($compact, 'county') || str_contains($compact, 'cnty') || str_starts_with($host, 'co.') || str_contains($host, '.co.');
        if (str_ends_with($host, '.gov') || str_ends_with($host, '.us')) {
            return $carriesName || $saysCounty || $position === 1;
        }
        if (preg_match('/\.(org|com|net)$/', $host) !== 1) {
            return false;
        }

        return $carriesName && $saysCounty;
    }

    /** The host without www., or null when it is one of the known non-government sites. */
    private static function bareHost(string $host): ?string
    {
        $host = strtolower(preg_replace('/^www\./', '', trim($host)) ?? $host);
        if ($host === '') {
            return null;
        }
        foreach (self::NEVER as $bad) {
            if (str_contains($host, $bad)) {
                return null;
            }
        }

        return $host;
    }

    /** A state's own portal ("pa.gov", "nj.gov", "state.nj.us", "state.pa.us") — never a town's or county's source. */
    public static function isStatePortal(string $host): bool
    {
        $host = strtolower(preg_replace('/^www\./', '', trim($host)) ?? $host);

        return preg_match('/^[a-z]{2}\.gov$/', $host) === 1 || preg_match('/^state\.[a-z]{2}\.us$/', $host) === 1;
    }

    /** The site's https front door — a search result deep in the site (or served over http) still cites the home page over https. */
    private static function homepage(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return "https://{$host}/";
    }

    /**
     * Re-check a resolved town's stored picks against the current rules without searching: a pick that no
     * longer passes is dropped. Returns true when a slot was dropped (the caller re-resolves the town).
     */
    public function revalidate(CoverageArea $area): bool
    {
        if (! is_array($area->local_sources)) {
            return false;
        }
        $town = trim((string) $area->name);
        $countyName = null;
        $geo = trim((string) $area->geo_id);
        if (strlen($geo) >= 5) {
            $countyName = $this->countyName(substr($geo, 0, 5));
        }
        $kept = [];
        $dropped = false;
        foreach ($area->local_sources as $src) {
            $host = (string) parse_url($src['url'], PHP_URL_HOST);
            $ok = match ($src['key']) {
                self::MUNICIPALITY => Str::startsWith($src['url'], 'https://') && self::isMunicipalHost($host, [$town]),
                self::COUNTY => Str::startsWith($src['url'], 'https://') && $countyName !== null && self::isCountyHost($host, $countyName, 1),
                default => true,
            };
            if ($ok) {
                $kept[] = $src;
            } else {
                $dropped = true;
            }
        }
        if ($dropped) {
            $area->forceFill(['local_sources' => $kept])->save();
        }

        return $dropped;
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
