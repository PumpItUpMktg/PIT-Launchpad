<?php

namespace App\Build;

use App\ContentEngine\BlogQueue\BlogTargetQueue;
use App\Enums\BuildSource;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\SpokeGranularity;
use App\Enums\StandardPageType;
use App\Locations\LocationLandingSync;
use App\Locations\LocationNesting;
use App\Locations\TownLocationAssigner;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use App\SiloCreator\PillarFactory;
use App\SiloCreator\SiloNesting;
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
        private readonly TargetKeywordResolver $keywords,
        private readonly BlogTargetQueue $blogQueue,
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

                // A location page targets ONE town — pin its market (the page_key is the source
                // CoverageArea id) so grounding foregrounds its own town, not the whole service area.
                $market = $entry->source === BuildSource::Location
                    ? $this->projector->marketForCoverageArea($entry->page_key, $site)
                    : null;

                // Carry the spoke's Pass-D keyword onto the page (before create, so the rail + grounding
                // read a real target). Resolve the string to a §5 Keyword — the single representation.
                $targetKeyword = $this->keywords->forSpoke($site, $entry->spoke_id, $silo?->id);

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
                    'market_id' => $market?->id,
                    'target_keyword_id' => $targetKeyword?->id,
                    'wireframe_kit_id' => $kit?->id,
                    'wireframe_kit_version' => $kit?->version,
                ]);

                $entry->forceFill(['content_id' => $content->id])->save();

                // Complete the bi-directional link so §5 coverage sees this keyword as covered (only when
                // the keyword isn't already pointed at another page — never clobber an existing target).
                if ($targetKeyword !== null && $targetKeyword->target_content_id === null) {
                    $targetKeyword->forceFill(['target_content_id' => $content->id])->save();
                }

                $pages[] = $content;
            }

            // Longtail routing: reconcile the silo blog-target queues with the confirmed blueprint
            // (silos exist now). Enqueues offered blog_target spokes' keywords; removes queued rows
            // for spokes flipped back to fold/page — one keyword, one home.
            $this->blogQueue->sync($site);

            // Live board grouping: pin each town page to the Location that serves its town
            // (served_towns; unique by the cannibalization guard). Unmatched pages stay for the
            // assign-location picker.
            app(TownLocationAssigner::class)->assign($site);

            // Ensure the landing/hub page exists per base Location (the page that IS the location —
            // its town pages nest beneath it). Idempotent; skips a location with nothing honest to say.
            app(LocationLandingSync::class)->sync($site);

            // URL nesting: pin each town page under its location hub and rewrite its slug to the full
            // nested path (/montclair/springfield), so duplicate town names across locations coexist.
            app(LocationNesting::class)->nest($site);

            // URL nesting for the silo tree: pin each child service page under its silo hub and nest its
            // slug (/drain-services/drain-cleaning), so the built pages match the Silos & pruning tree.
            app(SiloNesting::class)->nest($site);

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
            BuildSource::Service => $this->rendersAsHub($entry) ? PageType::Hub : PageType::Service,
            BuildSource::Location => PageType::Location,
        };
    }

    /**
     * Whether this Service page renders as a HUB (category, services grid) rather than a standalone
     * SERVICE page. The render-type is DECOUPLED from `is_pillar` (the silo-structural role): a spoke is
     * a hub IFF it heads a silo that actually has ≥1 own-page child to link — i.e. a pillar with
     * own_page siblings in its silo. A pillar with no own-page children (its own_page children were all
     * folded to sections, or it's a single-service silo) renders as a service page, not a spoke-less
     * "thin hub". This is the structural rule the author-declared grouping produces.
     */
    private function rendersAsHub(BuildPage $entry): bool
    {
        if ($entry->spoke_id === null) {
            return false;
        }

        $spoke = Spoke::withoutGlobalScope(SiteScope::class)->find($entry->spoke_id);
        if ($spoke === null || ! $spoke->is_pillar) {
            return false;
        }

        // A hub needs a real child to route to: another own_page spoke in the same silo.
        return Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $spoke->site_id)
            ->where('silo', $spoke->silo)
            ->where('is_pillar', false)
            ->where('granularity', SpokeGranularity::OwnPage->value)
            ->exists();
    }
}
