<?php

namespace App\Build;

use App\Enums\BuildSource;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use App\SiloCreator\PillarFactory;
use App\Standard\StandardKit;
use Illuminate\Support\Facades\DB;

/**
 * Materialize: turn the approved build manifest (BuildPage rows) into one **planned** `kind=page`
 * Content row each — cheap, instant, NO AI. This replaces the BuildRunner stub (which flipped
 * statuses without generating). Each row is created undrafted (status `candidate`, no slot_payload)
 * with its **permalink assigned up front**, so the full URL map exists before any drafting and the
 * per-page Build can write real internal links.
 *
 * Idempotent: each BuildPage is linked to its Content via `build_pages.content_id`, so re-running
 * approve reuses rather than duplicates. Grounding: {@see GuidedEntityProjector} first projects the
 * spoke structure into §1 Service + §4 Silo, then each service/hub page pins `silo_id` to its silo —
 * so the drafter grounds on the page's own service, not a site-wide guess. The wireframe kit is
 * resolved from the mapped page_type (service/location have kits; standard/hub don't → Build is
 * composer-pending until their composer ships).
 */
final class PageMaterializer
{
    public function __construct(
        private readonly Permalinks $permalinks,
        private readonly GuidedEntityProjector $projector,
    ) {}

    /**
     * @return list<Content> the materialized pages (one per manifest entry)
     */
    public function materialize(Site $site): array
    {
        return DB::transaction(function () use ($site): array {
            // Project the spoke structure into §1 Service + §4 Silo BEFORE materializing, so each
            // page can pin its silo and the drafter grounds on real entities.
            $this->projector->project($site);

            $manifest = BuildPage::query()
                ->where('site_id', $site->id)
                ->orderBy('priority')
                ->orderBy('id')
                ->get();

            $taken = $this->permalinks->takenSlugs($site);

            $pages = [];
            foreach ($manifest as $entry) {
                $existing = $this->linked($entry);
                if ($existing !== null) {
                    $pages[] = $existing;

                    continue;
                }

                $pageType = $this->pageType($entry);
                $slug = $this->permalinks->uniqueSlug($entry->title, $taken);
                $taken[] = $slug;

                // A standard page knows WHICH standard page it is (its page_key); the composer
                // resolves its kit by that finer identity (service/location resolve by page_type).
                $standardType = $entry->source === BuildSource::Standard
                    ? StandardPageType::tryFrom((string) $entry->page_key)
                    : null;

                $kit = $standardType !== null
                    ? StandardKit::resolve($standardType, $site->id)
                    : PillarFactory::resolveKit($pageType, $site->id);

                // Pin the page to its silo (service/hub pages) so grounding scopes to its own service.
                $silo = $entry->source === BuildSource::Service
                    ? $this->projector->siloForSpoke($entry->spoke_id, $site)
                    : null;

                // A service page is about ONE service — pin its subject so grounding scopes to that
                // service, not every sibling in the silo (a silo can hold a cluster: toilet
                // replacement / installation / repair). Hub/category pages cover the whole silo and
                // stay unpinned; location/standard pages have no service subject.
                $primaryService = ($entry->source === BuildSource::Service && $pageType === PageType::Service)
                    ? $this->projector->serviceForSpoke($entry->spoke_id, $site)
                    : null;

                $content = Content::create([
                    'site_id' => $site->id, // explicit: no current-site scope in console/job context
                    'kind' => ContentKind::Page,
                    'page_type' => $pageType,
                    'standard_type' => $standardType?->value,
                    'status' => ContentStatus::Candidate, // planned/undrafted — generationState 'awaiting'
                    'title' => $entry->title,
                    'slug' => $slug,
                    'version' => 1,
                    'silo_id' => $silo?->id,
                    'primary_service_id' => $primaryService?->id,
                    'wireframe_kit_id' => $kit?->id,
                    'wireframe_kit_version' => $kit?->version,
                ]);

                $entry->forceFill(['content_id' => $content->id])->save();
                $pages[] = $content;
            }

            return $pages;
        });
    }

    /** The already-materialized Content for this manifest entry, or null (idempotency key). */
    private function linked(BuildPage $entry): ?Content
    {
        if ($entry->content_id === null) {
            return null;
        }

        return Content::withoutGlobalScope(SiteScope::class)->find($entry->content_id);
    }

    private function pageType(BuildPage $entry): PageType
    {
        return match ($entry->source) {
            BuildSource::Standard => $entry->page_key === 'home' ? PageType::Home : PageType::Utility,
            BuildSource::Service => $this->isPillar($entry) ? PageType::Hub : PageType::Service,
            BuildSource::Location => PageType::Location,
        };
    }

    private function isPillar(BuildPage $entry): bool
    {
        if ($entry->spoke_id === null) {
            return false;
        }

        return (bool) (Spoke::withoutGlobalScope(SiteScope::class)->find($entry->spoke_id)?->is_pillar);
    }
}
