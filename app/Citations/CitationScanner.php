<?php

namespace App\Citations;

use App\Enums\CitationPresence;
use App\Enums\CitationSource;
use App\Enums\MultiLocationPolicy;
use App\Integrations\Citations\ListingVerifier;
use App\Integrations\Citations\NullListingVerifier;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoException;
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
 * For one location it runs two kinds of DataForSEO organic queries against the canonical NAP profile:
 *
 *  1. A few brand-anchored queries (brand + city, brand + phone, brand + street, brand) — DISCOVERY. Every
 *     result domain is persisted (`citation_found_domains`) and matched against the GLOBAL directory catalog.
 *     Most directory pages never rank in a brand SERP, so on its own this finds only the big ones.
 *  2. A targeted `site:{directory} "{brand}" {city}` check per APPLICABLE directory the discovery pass did not
 *     already find — PRESENCE. A result on the directory's own domain that looks like a listing page (not the
 *     directory's search page) and names the brand is that directory's listing.
 *
 * Every matched listing is ATTRIBUTED to the right sibling location BEFORE judging (Fix 1), the listing's real
 * NAP is read through the {@see ListingVerifier} where the directory allows it, and a `citation_status` row is
 * upserted. A result that can't be attributed to a clear owner is parked `needs_review`, never guessed.
 *
 * A listing found on an earlier scan but not this one counts a miss; after `citations.lost_after_misses`
 * consecutive misses it flips to Absent (the differ then records it lost). Platform-confirmed rows (Google,
 * from the location's own GBP) are never aged out by the SERP.
 *
 * One failed DataForSEO call never sinks the scan: a transient error (their SE hiccup, a timeout) skips
 * that query — a skipped directory is left UNCHECKED (no status written, never aged), and the run records
 * how many. Only an auth/quota failure, or every call failing, fails the run.
 */
final class CitationScanner
{
    /** Max brand-anchored queries per location (spec: 3–5). */
    private const MAX_QUERIES = 5;

    /** Listing pages kept per directory check — on a multi-location brand the first page may be a sibling's. */
    private const MAX_CANDIDATES = 3;

    /** URL path fragments that mark a directory's own search / browse page rather than one listing. */
    private const NON_LISTING_PATHS = ['/search', '/find', '/results', '/s?', 'find_desc=', '?q=', '/browse', '/category/', '/categories/', '/directory/', 'companylist', '/near-me', '/best-'];

    /** Directory ids whose presence check could not be made this scan (a failed call) — never aged out. @var list<string> */
    private array $unchecked = [];

    /** Every DataForSEO call this scan made: the transient failure it hit, or null when it answered. @var list<DataForSeoException|null> */
    private array $ledger = [];

    public function __construct(
        private readonly DataForSeoClient $dfs,
        private readonly CitationAttributor $attributor = new CitationAttributor,
        private readonly NapNormalizer $nap = new NapNormalizer,
        private readonly ListingVerifier $verifier = new NullListingVerifier,
        private readonly CitationApplicability $applicability = new CitationApplicability,
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

        $this->unchecked = [];
        $this->ledger = [];

        $siblings = $this->siblingDtos((string) $location->site_id);
        $sharedPhones = $this->sharedPhoneOwners((string) $location->site_id);
        $directories = Directory::query()->where('is_active', true)->get();

        // Discovery: collapse the brand-query result set to one entry per domain (a listing that ranks for
        // several queries is one listing).
        $found = $this->runQueries($this->buildQueries($profile));

        // Presence: a targeted check per applicable directory the discovery pass missed.
        $applicable = $this->applicability->forLocation($location);
        $found = $this->checkDirectories($profile, $applicable, $found);

        // Nothing at all could be asked → the run is a failure, not a scan that found nothing.
        $failures = array_values(array_filter($this->ledger));
        if ($failures !== [] && count($failures) === count($this->ledger)) {
            throw $failures[array_key_last($failures)];
        }

        $written = 0;
        $foundDirectoryIds = [];
        foreach ($found as $domain => $result) {
            $directory = $this->matchDirectory($domain, $directories);
            $this->persistFoundDomain($location, $domain, $directory?->id, $result['url']);

            if ($directory === null) {
                continue; // Unmatched candidate domain — kept for PR5/PR8 harvesting, no status row.
            }

            // Read the listing's real NAP so attribution can tell this location's listing from a sibling's,
            // and the NAP compare can fault a mismatch. Null (blocked / no data) leaves it needs-review.
            // On a multi-location brand the directory check may have surfaced several of the brand's pages:
            // verify each and keep the one attribution assigns to THIS location, so a sibling's page ranking
            // first never hides this location's own listing.
            $result = $this->pickOwnListing($location, $directory, $result, $siblings, $sharedPhones);

            $this->writeStatus($location, $directory, $result, $siblings, $sharedPhones);
            $foundDirectoryIds[] = (string) $directory->id;
            $written++;
        }

        $this->ageOutUnfound($location, $foundDirectoryIds);

        return $written;
    }

    /** Directory ids the last scan could not check (a failed DataForSEO call) — reported on the run. @return list<string> */
    public function lastUnchecked(): array
    {
        return $this->unchecked;
    }

    /** How many DataForSEO calls failed (and were skipped) on the last scan. */
    public function lastFailedCalls(): int
    {
        return count(array_filter($this->ledger));
    }

    /**
     * One organic query, tolerant: no-results → []; a transient failure (after the client's own retry) →
     * null (the caller skips that query); an auth/quota failure → rethrown, nothing is scannable through it.
     *
     * @return list<array{position: int, url: string, domain: string, title?: string}>|null
     *
     * @phpstan-impure writes the call ledger
     */
    private function organic(string $query, int $depth): ?array
    {
        $locationCode = (int) config('services.dataforseo.location_code', 2840);
        $language = (string) config('services.dataforseo.language_code', 'en');

        try {
            $rows = $this->dfs->liveOrganic($query, $locationCode, $language, $depth);
            $this->ledger[] = null;

            return $rows;
        } catch (DataForSeoException $e) {
            if ($e->statusCode === DataForSeoException::NO_SEARCH_RESULTS) {
                $this->ledger[] = null;

                return [];
            }
            if ($e->fatal) {
                throw $e;
            }
            $this->ledger[] = $e;

            return null;
        }
    }

    /**
     * Targeted presence checks: for each applicable directory not already found, ask the SERP for that
     * directory's own pages naming the brand. Accepts the first result that is on the directory's domain, is
     * not a search/browse page, and names the brand in its title or URL. Capped per scan (each is one call).
     *
     * @param  Collection<int, Directory>  $applicable
     * @param  array<string, array{url: string, name: ?string, address: ?string, phone: ?string, candidates?: list<array{url: string, title: string}>}>  $found
     * @return array<string, array{url: string, name: ?string, address: ?string, phone: ?string, candidates?: list<array{url: string, title: string}>}>
     *
     * @phpstan-impure writes the call ledger + unchecked list
     */
    private function checkDirectories(LocationNapProfile $profile, Collection $applicable, array $found): array
    {
        $name = trim((string) $profile->business_name);
        if ($name === '') {
            return $found;
        }
        $city = trim((string) ($profile->city ?? ''));
        $limit = max(0, (int) config('launchpad.citations.directory_query_limit', 40));

        $checked = 0;
        foreach ($applicable as $directory) {
            $domain = $this->normalizeDomain((string) $directory->domain);
            if ($domain === '' || $this->foundCovers($found, $domain)) {
                continue;
            }
            if ($checked >= $limit) {
                break;
            }
            $checked++;

            $query = 'site:'.$domain.' "'.$name.'"'.($city !== '' ? ' '.$city : '');
            $rows = $this->organic($query, 10);
            if ($rows === null) {
                $this->unchecked[] = (string) $directory->id; // could not be asked — left as it was, never aged

                continue;
            }
            $candidates = [];
            foreach ($rows as $row) {
                $rowDomain = $this->normalizeDomain((string) $row['domain']);
                $url = (string) $row['url'];
                $title = (string) ($row['title'] ?? '');
                if ($rowDomain === '' || ($rowDomain !== $domain && ! str_ends_with($rowDomain, '.'.$domain))) {
                    continue; // not on the directory — the SERP wandered
                }
                if (! $this->looksLikeListing($url, $title, $name)) {
                    continue;
                }
                $candidates[] = ['domain' => $rowDomain, 'url' => $url, 'title' => $title, 'city' => $city !== '' && $this->mentions($title.' '.$url, $city)];
                if (count($candidates) >= self::MAX_CANDIDATES) {
                    break;
                }
            }
            if ($candidates === []) {
                continue;
            }
            // A page naming the town goes first — on a multi-location brand that is most likely THIS
            // location's listing; the rest stay as fallbacks for attribution to sort out.
            usort($candidates, fn (array $a, array $b): int => (int) $b['city'] <=> (int) $a['city']);
            $found[$candidates[0]['domain']] = [
                'url' => $candidates[0]['url'],
                'title' => $candidates[0]['title'],
                'name' => null,
                'address' => null,
                'phone' => null,
                'candidates' => array_map(fn (array $c): array => ['url' => $c['url'], 'title' => $c['title']], $candidates),
            ];
        }

        return $found;
    }

    /** Whether the discovery pass already found a page on this directory (exact or subdomain). */
    private function foundCovers(array $found, string $domain): bool
    {
        foreach (array_keys($found) as $d) {
            if ($d === $domain || str_ends_with((string) $d, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A listing page names the brand (in the SERP title or the URL slug) and is not the directory's own
     * search / browse page. The brand test is token-based and requires EVERY distinctive word of the brand:
     * "Sump Pump Gurus" must show "gurus", so a directory's "sump pump installation in Downingtown" category
     * page — which matches the trade words but not the brand — is never mistaken for the business's listing.
     */
    private function looksLikeListing(string $url, string $title, string $brand): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH)).'?'.mb_strtolower((string) parse_url($url, PHP_URL_QUERY));
        foreach (self::NON_LISTING_PATHS as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        $tokens = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($brand)) ?: [],
            fn (string $t): bool => mb_strlen($t) >= 3 && ! in_array($t, ['the', 'and', 'llc', 'inc', 'co', 'company'], true),
        ));
        if ($tokens === []) {
            return true;
        }
        $haystack = mb_strtolower($title.' '.str_replace(['-', '_', '/', '.'], ' ', $url));

        return array_all($tokens, fn (string $t): bool => str_contains($haystack, $t));
    }

    /** Whether a title/URL haystack names a town (token-normalized, e.g. "newtown-pa" or "Newtown, PA"). */
    private function mentions(string $haystack, string $town): bool
    {
        $t = $this->nap->name($town);

        return $t !== '' && str_contains($this->nap->name(str_replace(['-', '_', '/'], ' ', $haystack)), $t);
    }

    /**
     * Age out listings this scan did not find: a previously-present row counts a miss, and after
     * `lost_after_misses` consecutive misses flips to Absent. Rows confirmed from the platform (GBP) and rows
     * that are already Absent are left alone; a row found this pass resets its miss count.
     *
     * @param  list<string>  $foundDirectoryIds
     */
    private function ageOutUnfound(Location $location, array $foundDirectoryIds): void
    {
        $threshold = max(1, (int) config('launchpad.citations.lost_after_misses', 2));
        $now = Carbon::now();

        $rows = CitationStatus::query()
            ->where('location_id', $location->id)
            ->whereIn('presence', [CitationPresence::PresentMatch->value, CitationPresence::PresentMismatch->value, CitationPresence::Unknown->value])
            ->where('source', '!=', CitationSource::Platform->value)
            ->get();

        foreach ($rows as $row) {
            if (in_array((string) $row->directory_id, $foundDirectoryIds, true)) {
                if ($row->missed_scans !== 0) {
                    $row->forceFill(['missed_scans' => 0])->save();
                }

                continue;
            }
            if (in_array((string) $row->directory_id, $this->unchecked, true)) {
                continue; // not a miss — the check itself failed this pass
            }

            $misses = $row->missed_scans + 1;
            $fill = ['missed_scans' => $misses, 'last_scanned_at' => $now];
            if ($misses >= $threshold) {
                $fill += ['presence' => CitationPresence::Absent, 'needs_review' => false, 'mismatch_fields' => null];
            }
            $row->forceFill($fill)->save();
        }
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
                $this->writeStatus($anchor, $directory, $result, $siblings, $sharedPhones);
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
     *
     * @phpstan-impure writes the call ledger
     */
    private function runQueries(array $queries): array
    {
        $depth = (int) config('services.dataforseo.serp_depth', 20);

        $out = [];
        foreach ($queries as $query) {
            $rows = $this->organic($query, $depth);
            if ($rows === null) {
                continue; // this query failed on DataForSEO's side — the others still carry discovery
            }
            foreach ($rows as $row) {
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
     * Verify the found page (and, on a multi-location tenant, each alternative candidate page from the
     * directory check) and return the result that attribution assigns to this location — else the first
     * candidate, verified, so the normal judging (sibling's → Absent, unclear → needs review) applies.
     *
     * @param  array{url: string, title?: string, name: ?string, address: ?string, phone: ?string, candidates?: list<array{url: string, title: string}>}  $result
     * @param  list<array{location_id: string, phone_primary?: ?string, address_1?: ?string, city?: ?string, postal?: ?string}>  $siblings
     * @param  array<string, ?string>  $sharedPhones
     * @return array{url: string, title: string, name: ?string, address: ?string, phone: ?string}
     */
    private function pickOwnListing(Location $location, Directory $directory, array $result, array $siblings, array $sharedPhones): array
    {
        $domain = $this->normalizeDomain((string) $directory->domain);
        $pages = $result['candidates'] ?? [['url' => $result['url'], 'title' => (string) ($result['title'] ?? '')]];
        if (! in_array($result['url'], array_column($pages, 'url'), true)) {
            array_unshift($pages, ['url' => $result['url'], 'title' => (string) ($result['title'] ?? '')]);
        }
        // A single-location tenant, or a one-listing-per-business directory (Facebook, BBB…): the first page
        // is the business's — "which location" is not a question there. One fetch.
        $noAttribution = count($siblings) <= 1 || $directory->multi_location_policy === MultiLocationPolicy::OnePerBusiness;
        if ($noAttribution) {
            $pages = [$pages[0]];
        }

        $verifiedCandidates = [];
        foreach ($pages as $page) {
            $candidate = ['url' => $page['url'], 'title' => $page['title'], 'name' => null, 'address' => null, 'phone' => null];
            $verified = $this->verifier->verify($domain, $page['url']);
            if ($verified !== null) {
                $candidate['name'] = $verified->name;
                $candidate['address'] = $verified->address;
                $candidate['phone'] = $verified->phone;
            }
            if ($noAttribution) {
                return $candidate;
            }
            $attr = $this->attributor->attribute($candidate, $siblings, $sharedPhones);
            if (! $attr->ambiguous && $attr->locationId === (string) $location->id) {
                return $candidate;
            }
            $verifiedCandidates[] = $candidate;
        }

        // None attributed to this location: judge the first page as found (sibling's → Absent, unclear → review).
        return $verifiedCandidates[0];
    }

    /**
     * Attribute a matched listing to the correct sibling, judge its state, and upsert the status row.
     *
     * @param  array{url: string, title?: string, name: ?string, address: ?string, phone: ?string}  $result
     * @param  list<array{location_id: string, phone_primary?: ?string, address_1?: ?string, city?: ?string, postal?: ?string}>  $siblings
     * @param  array<string, ?string>  $sharedPhones
     */
    private function writeStatus(Location $location, Directory $directory, array $result, array $siblings, array $sharedPhones): void
    {
        $directoryId = (string) $directory->id;
        // Single-location tenants have no attribution ambiguity — the listing is theirs; nor does a
        // one-listing-per-business directory (the business has ONE page, every location "has" it).
        // Otherwise route through the scorer; a page that can't be told apart lands unknown + needs_review.
        if (count($siblings) === 1 || $directory->multi_location_policy === MultiLocationPolicy::OnePerBusiness) {
            $attr = new AttributionResult((string) $location->id, 100, false);
        } else {
            $attr = $this->attributor->attribute($result, $siblings, $sharedPhones);
        }

        $profile = LocationNapProfile::query()->where('location_id', $location->id)->first();
        $canonical = $profile !== null ? [
            'business_name' => (string) $profile->business_name,
            'address_1' => (string) ($profile->address_1 ?? ''),
            'address_2' => (string) ($profile->address_2 ?? ''),
            'postal' => (string) ($profile->postal ?? ''),
            'phone' => (string) ($profile->phone_primary ?? ''),
        ] : null;

        $mismatches = [];
        $needsReview = false;
        $presence = $this->judge($attr, (string) $location->id, $result, $canonical, array_keys($sharedPhones), $mismatches, $needsReview);

        $now = Carbon::now();
        // Presence is written on EVERY scan (its own axis); lifecycle and covered_by_sibling are owned by
        // CitationLifecycle / the reconciler and are left untouched — updateOrCreate only sets what's listed.
        CitationStatus::query()->updateOrCreate(
            ['location_id' => $location->id, 'directory_id' => $directoryId],
            [
                'site_id' => $location->site_id,
                'presence' => $presence,
                'needs_review' => $needsReview,
                'found_url' => $result['url'],
                'found_name' => $result['name'],
                'found_address' => $result['address'],
                'found_phone' => $result['phone'],
                'attributed_location_id' => $attr->locationId,
                'attribution_confidence' => $attr->confidence,
                'mismatch_fields' => $mismatches === [] ? null : $mismatches,
                'source' => CitationSource::Unknown,
                'last_scanned_at' => $now,
                'missed_scans' => 0,
            ],
        );

        // Stamp first_seen_at once.
        CitationStatus::query()
            ->where('location_id', $location->id)->where('directory_id', $directoryId)
            ->whereNull('first_seen_at')
            ->update(['first_seen_at' => $now]);
    }

    /**
     * Decide the citation PRESENCE from attribution + NAP comparison. `$mismatches` and `$needsReview` are
     * populated by reference. Presence is the scanner's axis alone — it never decides lifecycle.
     *
     * @param  array{url: string, title?: string, name: ?string, address: ?string, phone: ?string}  $result
     * @param  array{business_name: string, address_1: string, address_2?: ?string, postal?: ?string, phone?: ?string}|null  $canonical
     * @param  list<string>  $sharedPhones  normalized shared/corporate numbers (never a phone mismatch)
     * @param  array<string, array{found: string, expected: string}>  $mismatches
     *
     * @param-out array<string, array{found: string, expected: string}> $mismatches
     */
    private function judge(AttributionResult $attr, string $scannedLocationId, array $result, ?array $canonical, array $sharedPhones, array &$mismatches, bool &$needsReview): CitationPresence
    {
        $mismatches = [];
        $needsReview = false;

        if ($attr->ambiguous || $attr->locationId === null) {
            $needsReview = true; // attribution too weak/tied to auto-decide — operator resolves

            return CitationPresence::Unknown;
        }
        if ($attr->locationId !== $scannedLocationId) {
            // This result is a sibling's listing — for the scanned location it is simply not present.
            return CitationPresence::Absent;
        }

        $hasFoundNap = ($result['name'] ?? null) !== null || ($result['address'] ?? null) !== null || ($result['phone'] ?? null) !== null;
        if (! $hasFoundNap || $canonical === null) {
            return CitationPresence::PresentMatch; // present and ours; no scraped NAP to fault it on
        }

        $mismatches = $this->nap->mismatches(
            ['name' => $result['name'], 'address' => $result['address'], 'phone' => $result['phone']],
            $canonical,
            $sharedPhones,
        );

        return $mismatches === [] ? CitationPresence::PresentMatch : CitationPresence::PresentMismatch;
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
