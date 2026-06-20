<?php

namespace App\Build;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Enums\SpokeStatus;
use App\Enums\StandardPageType;
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
     * @return list<array<string, mixed>>
     */
    private function standardRows(Site $site): array
    {
        $rows = [];
        foreach ($this->standardPages->forSite($site) as $type) {
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
     * Location pages = the towns flagged into the page-drip pool (page_selected).
     *
     * @return list<array<string, mixed>>
     */
    private function locationRows(Site $site): array
    {
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_selected', true)
            ->orderByDesc('population')
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($towns->values() as $i => $town) {
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
        }

        return $rows;
    }
}
