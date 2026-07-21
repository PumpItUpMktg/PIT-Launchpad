<?php

namespace App\Build;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Enums\SpokeStatus;
use App\Enums\StandardPageType;
use App\Locations\LocationLandingSync;
use App\Models\BuildPage;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use App\Standard\StandardPages;
use Illuminate\Support\Facades\DB;

/**
 * Assembles a site's build manifest on approval: every page across the three sources —
 * **Standard** (accepted scaffold), **Service** (the finalized silo structure), **Location**
 * (page_selected towns). Each entry carries type, source, content recipe, status, and priority,
 * with brand-critical pages flagged for review. Build order via priority: Home + fixed standard
 * first (the site must be launchable), then top service pages by volume, then long-tail service
 * + town pages (the drip). Idempotent — re-assembly upserts on (site, source, key).
 */
class BuildManifestAssembler
{
    public function __construct(
        private readonly StandardPages $standardPages,
    ) {}

    /** @return list<BuildPage> the assembled manifest */
    public function assemble(Site $site): array
    {
        return DB::transaction(function () use ($site): array {
            $rows = [
                ...$this->standardRows($site),
                ...$this->serviceRows($site),
                ...$this->locationRows($site),
            ];

            $built = [];
            foreach ($rows as $row) {
                $built[] = BuildPage::query()->updateOrCreate(
                    ['site_id' => $site->id, 'source' => $row['source']->value, 'page_key' => $row['page_key']],
                    $row + ['site_id' => $site->id],
                );
            }

            return $built;
        });
    }

    /**
     * The full page set WITHOUT persisting — what the Page Inventory previews at blueprint
     * confirmation. Standard uses the DEFAULT set (fixed + every offerable optional, defaulted on)
     * so the inventory shows what Build would build by default; the client's accept/decline at
     * Approve narrows it via {@see assemble()} (same row logic, no drift).
     *
     * @return array{standard: list<array<string, mixed>>, service: list<array<string, mixed>>, location: list<array<string, mixed>>}
     */
    public function preview(Site $site): array
    {
        return [
            'standard' => $this->standardRowsFor($this->standardPages->defaultForSite($site)),
            'service' => $this->serviceRows($site),
            'location' => $this->locationRows($site),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function standardRows(Site $site): array
    {
        return $this->standardRowsFor($this->standardPages->forSite($site));
    }

    /**
     * @param  list<StandardPageType>  $types
     * @return list<array<string, mixed>>
     */
    private function standardRowsFor(array $types): array
    {
        $rows = [];
        foreach ($types as $type) {
            $priority = $type === StandardPageType::Home ? 0 : ($type->isFixed() ? 10 : 20);
            $rows[] = [
                'source' => BuildSource::Standard,
                'page_key' => $type->value,
                'title' => $type->label(),
                'recipe' => $type->recipe(),
                'status' => BuildStatus::Queued,
                'priority' => $priority,
                'review_required' => $type->isBrandCritical(),
                'spoke_id' => null,
            ];
        }

        return $rows;
    }

    /**
     * Service pages = the confirmed own-page cores + hubs (folded spokes are sections, not pages),
     * ordered by volume so the top pages build first.
     *
     * @return list<array<string, mixed>>
     */
    private function serviceRows(Site $site): array
    {
        $pages = Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('status', [SpokeStatus::Offered->value, SpokeStatus::Future->value, SpokeStatus::Content->value])
            ->where(fn ($q) => $q->where('is_pillar', true)->orWhere('granularity', 'own_page'))
            ->orderByDesc('volume')
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($pages->values() as $i => $spoke) {
            $rows[] = [
                'source' => BuildSource::Service,
                'page_key' => $spoke->id,
                'title' => $spoke->name,
                'recipe' => 'service.page',
                'status' => BuildStatus::Queued,
                'priority' => 100 + $i, // volume-desc rank
                'review_required' => false,
                'spoke_id' => $spoke->id,
            ];
        }

        return $rows;
    }

    /**
     * Location pages = the towns flagged into the page-drip pool (page_selected), EXCEPT a town that
     * is a physical location's own city. A brick-and-mortar location already gets its landing/hub page
     * ({@see LocationLandingSync}) titled for that city — planning a separate "town" page
     * for the same place would just duplicate it (two Downingtown pages competing for the same term).
     * So the location's own city is dropped here; its surrounding served towns still get their pages.
     *
     * @return list<array<string, mixed>>
     */
    private function locationRows(Site $site): array
    {
        $physicalCities = $this->physicalLocationCities($site);

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_selected', true)
            ->orderByDesc('population')
            ->orderBy('name')
            ->get();

        $rows = [];
        $i = 0;
        foreach ($towns->values() as $town) {
            if ($this->isPhysicalLocationCity((string) $town->name, $town->state, $physicalCities)) {
                continue; // the physical location's landing page already IS this town's page
            }

            $rows[] = [
                'source' => BuildSource::Location,
                'page_key' => $town->id,
                'title' => $town->name.($town->state !== null ? ', '.$town->state : ''),
                'recipe' => 'location.town',
                'status' => BuildStatus::Queued,
                'priority' => 500 + $i,
                'review_required' => false,
                'spoke_id' => null,
            ];
            $i++;
        }

        return $rows;
    }

    /**
     * Each physical Location's OWN city (its GBP locality, or the location name as a fallback), keyed
     * lowercased → the set of states that city appears in ('' when the state is unknown). Used to drop
     * a town page that would duplicate a brick-and-mortar location's landing page.
     *
     * @return array<string, list<string>>
     */
    private function physicalLocationCities(Site $site): array
    {
        $keys = [];
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        foreach ($locations as $location) {
            ['city' => $city, 'state' => $state] = $location->cityState();
            $city = trim($city) !== '' ? trim($city) : trim((string) $location->name);
            if ($city === '') {
                continue;
            }
            $keys[mb_strtolower($city)][] = strtoupper(trim($state));
        }

        return $keys;
    }

    /**
     * Is this coverage town a physical location's own city? Match on the normalized name; when both
     * sides carry a state they must agree (so a same-named town in a different state still gets a page),
     * but an unknown state on either side is treated as a match (the common single-footprint case).
     *
     * @param  array<string, list<string>>  $physicalCities
     */
    private function isPhysicalLocationCity(string $name, ?string $state, array $physicalCities): bool
    {
        $key = mb_strtolower(trim($name));
        if (! isset($physicalCities[$key])) {
            return false;
        }

        $townState = strtoupper(trim((string) $state));
        foreach ($physicalCities[$key] as $locationState) {
            if ($locationState === '' || $townState === '' || $locationState === $townState) {
                return true;
            }
        }

        return false;
    }
}
