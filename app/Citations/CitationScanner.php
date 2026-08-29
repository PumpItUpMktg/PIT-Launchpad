<?php

namespace App\Citations;

use App\Enums\CitationSource;
use App\Enums\CitationState;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\CitationFoundDomain;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\TenantSharedPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Citation Management — the monthly directory scan (§ Citations, PR2).
 *
 * For one location it builds a few brand-anchored SERP queries from the canonical NAP profile, runs them
 * through DataForSEO organic search, and for every result domain: persists it (`citation_found_domains` — the
 * module keeps its own SERP domains because platform rank-tracking is cache-only), matches it against the
 * GLOBAL directory catalog, ATTRIBUTES it to the right sibling location BEFORE judging (Fix 1), then records a
 * `citation_status` row. A result that can't be attributed to a clear owner is parked as `ambiguous_review`,
 * never guessed — mis-attributing a sibling's correct listing would break a live citation.
 *
 * SERP-only (organic) presence carries no scraped NAP, so a confidently-attributed listing with nothing to
 * compare against is `unverified` (present, not yet confirmed). When a caller supplies found NAP fields the
 * mismatch check runs and the state sharpens to `listed_correct` / `needs_fix`.
 */
final class CitationScanner
{
    /** Max brand-anchored queries per location (spec: 3–5). */
    private const MAX_QUERIES = 5;

    public function __construct(
        private readonly DataForSeoClient $dfs,
        private readonly CitationAttributor $attributor = new CitationAttributor,
        private readonly NapNormalizer $nap = new NapNormalizer,
    ) {}

    /**
     * Scan one location against the directory catalog. Returns the number of citation_status rows written.
     */
    public function scanLocation(Location $location): int
    {
        $profile = LocationNapProfile::query()->where('location_id', $location->id)->first();
        if ($profile === null) {
            return 0; // No canonical NAP → nothing authoritative to scan against.
        }

        $siblings = $this->siblingDtos((string) $location->site_id);
        $sharedPhones = $this->sharedPhoneOwners((string) $location->site_id);
        $directories = Directory::query()->where('is_active', true)->get();

        // Collapse the multi-query result set to one entry per domain (a listing that ranks for several
        // queries is one listing). The query anchoring is enough signal for presence.
        $found = $this->runQueries($this->buildQueries($profile));

        $written = 0;
        foreach ($found as $domain => $result) {
            $directory = $this->matchDirectory($domain, $directories);
            $this->persistFoundDomain($location, $domain, $directory?->id, $result['url']);

            if ($directory === null) {
                continue; // Unmatched candidate domain — kept for PR5/PR8 harvesting, no status row.
            }

            $this->writeStatus($location, $directory->id, $result, $siblings, $sharedPhones);
            $written++;
        }

        return $written;
    }

    /**
     * The tenant-wide shared-number sweep (Fix 7): query each shared/corporate phone once and attribute the
     * directory listings it surfaces to the number's OWNER (or park as ambiguous when un-owned). Returns rows
     * written. Runs per site, independent of any one location's queries.
     */
    public function sweepSharedNumbers(string $siteId): int
    {
        $shared = TenantSharedPhone::query()->where('site_id', $siteId)->get();
        if ($shared->isEmpty()) {
            return 0;
        }

        $siblings = $this->siblingDtos($siteId);
        $sharedPhones = $this->sharedPhoneOwners($siteId);
        $directories = Directory::query()->where('is_active', true)->get();

        $written = 0;
        foreach ($shared as $line) {
            $owner = $line->owning_location_id !== null
                ? Location::query()->find($line->owning_location_id)
                : null;
            // Attribute the sweep's results to the owning location's row when known; otherwise there is no
            // location to hang a status on, so we only persist found domains for the operator to triage.
            $anchor = $owner ?? Location::query()->where('site_id', $siteId)->first();
            if ($anchor === null) {
                continue;
            }

            $found = $this->runQueries(['"'.$line->phone.'"']);
            foreach ($found as $domain => $result) {
                $directory = $this->matchDirectory($domain, $directories);
                $this->persistFoundDomain($anchor, $domain, $directory?->id, $result['url']);
                if ($directory === null) {
                    continue;
                }
                $result['phone'] = $line->phone;
                $this->writeStatus($anchor, $directory->id, $result, $siblings, $sharedPhones);
                $written++;
            }
        }

        return $written;
    }

    /**
     * Build the brand-anchored query set from the canonical NAP profile. Brand + geo, brand + phone, brand +
     * street, brand alone — capped at {@see self::MAX_QUERIES}.
     *
     * @return list<string>
     */
    private function buildQueries(LocationNapProfile $profile): array
    {
        $name = trim((string) $profile->business_name);
        if ($name === '') {
            return [];
        }
        $city = trim((string) ($profile->city ?? ''));
        $state = trim((string) ($profile->state ?? ''));
        $phone = trim((string) ($profile->phone_primary ?? ''));
        $addr = trim((string) ($profile->address_1 ?? ''));

        $queries = [
            trim($name.' '.trim($city.' '.$state)),
            $phone !== '' ? $name.' '.$phone : '',
            $addr !== '' ? $name.' '.$addr : '',
            $name,
        ];

        $queries = array_values(array_unique(array_filter($queries, fn (string $q): bool => trim($q) !== '')));

        return array_slice($queries, 0, self::MAX_QUERIES);
    }

    /**
     * Run each query through DataForSEO organic search and collapse to one result per domain (first seen wins
     * the URL). Presence is what matters; the query anchoring is the signal.
     *
     * @param  list<string>  $queries
     * @return array<string, array{url: string, name: ?string, address: ?string, phone: ?string}>
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
                if ($domain === '' || isset($out[$domain])) {
                    continue;
                }
                $out[$domain] = [
                    'url' => (string) $row['url'],
                    'name' => null,
                    'address' => null,
                    'phone' => null,
                ];
            }
        }

        return $out;
    }

    /**
     * Attribute a matched listing to the correct sibling, judge its state, and upsert the status row.
     *
     * @param  array{url: string, name: ?string, address: ?string, phone: ?string}  $result
     * @param  list<array{location_id: string, phone_primary?: ?string, address_1?: ?string, city?: ?string, postal?: ?string}>  $siblings
     * @param  array<string, ?string>  $sharedPhones
     */
    private function writeStatus(Location $location, string $directoryId, array $result, array $siblings, array $sharedPhones): void
    {
        // Single-location tenants have no attribution ambiguity — the listing is theirs. Multi-location
        // tenants route through the scorer, and organic-only results (no scraped NAP) that can't be told
        // apart correctly land in ambiguous_review.
        if (count($siblings) === 1) {
            $attr = new AttributionResult((string) $location->id, 100, false);
        } else {
            $attr = $this->attributor->attribute($result, $siblings, $sharedPhones);
        }

        $profile = LocationNapProfile::query()->where('location_id', $location->id)->first();
        $canonical = $profile !== null ? [
            'business_name' => (string) $profile->business_name,
            'address_1' => (string) ($profile->address_1 ?? ''),
            'address_2' => (string) ($profile->address_2 ?? ''),
        ] : null;

        $mismatches = [];
        $state = $this->judge($attr, (string) $location->id, $result, $canonical, $mismatches);

        $now = Carbon::now();
        CitationStatus::query()->updateOrCreate(
            ['location_id' => $location->id, 'directory_id' => $directoryId],
            [
                'site_id' => $location->site_id,
                'state' => $state,
                'found_url' => $result['url'],
                'found_name' => $result['name'],
                'found_address' => $result['address'],
                'found_phone' => $result['phone'],
                'attributed_location_id' => $attr->locationId,
                'attribution_confidence' => $attr->confidence,
                'mismatch_fields' => $mismatches === [] ? null : $mismatches,
                'source' => CitationSource::Unknown,
                'last_scanned_at' => $now,
            ],
        );

        // Stamp first_seen_at once.
        CitationStatus::query()
            ->where('location_id', $location->id)->where('directory_id', $directoryId)
            ->whereNull('first_seen_at')
            ->update(['first_seen_at' => $now]);
    }

    /**
     * Decide the citation state from attribution + NAP comparison. `$mismatches` is populated by reference.
     *
     * @param  array{url: string, name: ?string, address: ?string, phone: ?string}  $result
     * @param  array{business_name: string, address_1: string, address_2?: ?string}|null  $canonical
     * @param  array<string, array{found: string, expected: string}>  $mismatches
     *
     * @param-out array<string, array{found: string, expected: string}> $mismatches
     */
    private function judge(AttributionResult $attr, string $scannedLocationId, array $result, ?array $canonical, array &$mismatches): CitationState
    {
        $mismatches = [];

        if ($attr->ambiguous || $attr->locationId === null) {
            return CitationState::AmbiguousReview;
        }
        if ($attr->locationId !== $scannedLocationId) {
            // A sibling owns this listing — it can NEVER become a fix/duplicate for the scanned location.
            return CitationState::SiblingListing;
        }

        $hasFoundNap = ($result['name'] ?? null) !== null || ($result['address'] ?? null) !== null;
        if (! $hasFoundNap || $canonical === null) {
            return CitationState::Unverified; // Present and ours, but nothing scraped to confirm against.
        }

        $mismatches = $this->nap->mismatches(
            ['name' => $result['name'], 'address' => $result['address']],
            $canonical,
        );

        return $mismatches === [] ? CitationState::ListedCorrect : CitationState::NeedsFix;
    }

    /** @return list<array{location_id: string, phone_primary: ?string, address_1: ?string, city: ?string, postal: ?string}> */
    private function siblingDtos(string $siteId): array
    {
        $locations = Location::query()->where('site_id', $siteId)->get();
        $profiles = LocationNapProfile::query()
            ->whereIn('location_id', $locations->pluck('id')->all())
            ->get()->keyBy('location_id');

        return $locations->map(function (Location $loc) use ($profiles): array {
            $p = $profiles->get($loc->id);

            return [
                'location_id' => (string) $loc->id,
                'phone_primary' => $p?->phone_primary,
                'address_1' => $p?->address_1,
                'city' => $p?->city,
                'postal' => $p?->postal,
            ];
        })->all();
    }

    /** @return array<string, ?string> normalized phone => owning location id (null = un-owned shared line) */
    private function sharedPhoneOwners(string $siteId): array
    {
        $out = [];
        foreach (TenantSharedPhone::query()->where('site_id', $siteId)->get() as $line) {
            $out[$this->nap->phone((string) $line->phone)] = $line->owning_location_id !== null
                ? (string) $line->owning_location_id
                : null;
        }

        return $out;
    }

    private function persistFoundDomain(Location $location, string $domain, ?string $directoryId, string $url): void
    {
        $now = Carbon::now();
        CitationFoundDomain::query()->updateOrCreate(
            ['site_id' => $location->site_id, 'location_id' => $location->id, 'domain' => $domain],
            ['directory_id' => $directoryId, 'found_url' => $url, 'last_seen_at' => $now],
        );
        CitationFoundDomain::query()
            ->where('site_id', $location->site_id)->where('location_id', $location->id)->where('domain', $domain)
            ->whereNull('first_seen_at')
            ->update(['first_seen_at' => $now]);
    }

    /**
     * Match a found domain to a catalog directory by exact or subdomain suffix ("biz.yelp.com" → "yelp.com").
     *
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
