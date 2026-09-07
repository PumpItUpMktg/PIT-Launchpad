<?php

namespace App\Build;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Enums\SpokeStatus;
use App\Enums\StandardPageType;
use App\Locations\LocationLandingSync;
use App\Locations\PhysicalLocationCities;
use App\Models\BuildPage;
use App\Models\CoverageArea;
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
        private readonly PhysicalLocationCities $physicalCities,
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
        $physicalCities = $this->physicalCities->forSite($site);

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_selected', true)
            ->orderByDesc('population')
            ->orderBy('name')
            ->orderBy('id') // stable representative when a town has duplicate coverage rows
            ->get();

        $rows = [];
        $i = 0;
        $seen = [];
        foreach ($towns->values() as $town) {
            if ($this->physicalCities->matches((string) $town->name, $town->state, $physicalCities)) {
                continue; // the physical location's landing page already IS this town's page
            }

            // ONE page per distinct (town, state): a town selected through duplicate CoverageArea rows
            // (the same place reached by several locations, or a duplicated coverage row) must not each
            // mint its own page — that is exactly what produced the bridgewater-nj-3/-4 duplicate slugs.
            $dedupeKey = $this->townDedupeKey((string) $town->name, $town->state);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

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

    /** The one-page-per-town key: normalized town name + state, so duplicate coverage rows collapse. */
    private function townDedupeKey(string $name, ?string $state): string
    {
        $town = mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));

        return $town.'|'.strtoupper(trim((string) $state));
    }
}
