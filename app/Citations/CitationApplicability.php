<?php

namespace App\Citations;

use App\Enums\DirectoryScope;
use App\Locations\TownLocationAssigner;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Models\TenantDirectoryExclusion;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides which catalog directories apply to which location (§ Citations, PR3).
 *
 * The multi-location correctness point: a geo-scoped directory must map to the ONE location that owns the geo,
 * never every sibling. A town directory for Clifton applies only to the location that serves Clifton (resolved
 * through the same coverage/served-towns ownership {@see TownLocationAssigner} uses for town pages) — otherwise
 * every sibling would be handed a work order for a listing only one of them can claim. National directories
 * apply to all; state/county directories apply to the locations physically in that geo; an optional trade
 * filter narrows a niche directory to matching categories.
 */
final class CitationApplicability
{
    public function __construct(private readonly TownLocationAssigner $towns = new TownLocationAssigner) {}

    /**
     * The active directories that apply to one location.
     *
     * @return Collection<int, Directory>
     */
    public function forLocation(Location $location): Collection
    {
        $site = Site::query()->findOrFail($location->site_id);
        $profile = LocationNapProfile::query()->where('location_id', $location->id)->first();

        $state = $this->normalizeState((string) ($profile->state ?? ''));
        $countyGeoids = $this->countyGeoids($location);

        $townOwners = $this->towns->townOwners($site);
        $ownedTowns = array_keys(array_filter($townOwners, fn (string $ownerId): bool => $ownerId === (string) $location->id));
        $isSoleLocation = Location::query()->where('site_id', $location->site_id)->count() === 1;

        $categories = $this->lowerList($profile?->categories);

        // Tenant-level exclusions apply to every location the tenant owns — an excluded directory is never
        // eligible anywhere for that tenant.
        $excluded = TenantDirectoryExclusion::query()
            ->where('site_id', $location->site_id)
            ->pluck('directory_id')
            ->map('strval')->flip();

        /** @var Collection<int, Directory> $directories */
        $directories = Directory::query()->where('is_active', true)->get();

        return $directories->filter(function (Directory $dir) use ($state, $countyGeoids, $ownedTowns, $isSoleLocation, $categories, $excluded): bool {
            return ! $excluded->has((string) $dir->id)
                && $this->geoApplies($dir, $state, $countyGeoids, $ownedTowns, $isSoleLocation)
                && $this->tradeApplies($dir, $categories);
        })->values();
    }

    private function geoApplies(Directory $dir, string $state, array $countyGeoids, array $ownedTowns, bool $isSoleLocation): bool
    {
        $geo = $this->normalizeGeo((string) ($dir->geo_value ?? ''));

        return match ($dir->scope) {
            DirectoryScope::National => true,
            DirectoryScope::State => $state !== '' && $geo === $state,
            // County geo_value may be a FIPS geoid (precise — match the location's counties) or a county name
            // (no name↔geoid crosswalk on Location yet, so fall back to in-state applicability and let the
            // operator prune — surfacing beats silently hiding a citation).
            DirectoryScope::County => ctype_digit($geo)
                ? in_array($geo, $countyGeoids, true)
                : $state !== '',
            // A town directory belongs to the location that owns the town — or the sole location, which owns
            // every town it serves (nothing to disambiguate).
            DirectoryScope::Town => $isSoleLocation || ($geo !== '' && in_array($geo, $ownedTowns, true)),
        };
    }

    /**
     * A trade-niche directory (one carrying `trade_categories`) applies only where the location's categories
     * intersect it. Permissive when either side is unknown — better to surface a citation for the operator to
     * prune than to silently hide one.
     *
     * @param  list<string>  $categories
     */
    private function tradeApplies(Directory $dir, array $categories): bool
    {
        $trade = $this->lowerList($dir->trade_categories);
        if ($trade === [] || $categories === []) {
            return true;
        }

        return array_intersect($trade, $categories) !== [];
    }

    /** @return list<string> */
    private function countyGeoids(Location $location): array
    {
        $ids = [];
        if ($location->home_county_geoid !== null && (string) $location->home_county_geoid !== '') {
            $ids[] = (string) $location->home_county_geoid;
        }
        foreach ($location->county_geoids ?? [] as $geoid) {
            if ((string) $geoid !== '') {
                $ids[] = (string) $geoid;
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalizeState(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** Town names and state codes normalize the same way; county geoids pass through lowercased-trimmed. */
    private function normalizeGeo(string $value): string
    {
        $v = trim((string) preg_replace('/,\s*[A-Za-z]{2}$/', '', trim($value)));

        return mb_strtolower($v);
    }

    /**
     * @param  list<string>|null  $values
     * @return list<string>
     */
    private function lowerList(?array $values): array
    {
        return array_values(array_filter(array_map(
            fn ($v): string => mb_strtolower(trim((string) $v)),
            $values ?? [],
        ), fn (string $v): bool => $v !== ''));
    }
}
