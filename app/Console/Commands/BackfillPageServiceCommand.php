<?php

namespace App\Console\Commands;

use App\Build\GuidedEntityProjector;
use App\Build\PageMaterializer;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Backfill {@see Content::$primary_service_id} on EXISTING service pages. The grounding fix pins a
 * page's own service at materialize, but {@see PageMaterializer} is idempotent and skips
 * already-linked rows — so pages materialized before the fix keep grounding on every sibling in
 * their silo (the toilet-replacement → sewer-backup bleed). This pins them after the fact so a
 * re-generate grounds correctly.
 *
 * Resolution mirrors materialize: the page's BuildPage carries the source spoke, and
 * {@see GuidedEntityProjector::serviceForSpoke} maps that spoke to the same §1 Service the
 * projection created. `--service=` force-pins a specific page (the escape hatch when the spoke link
 * is gone or wrong — e.g. correcting one known page). `--dry-run` reports without writing.
 *
 * Pinning does NOT touch the live WordPress page: a pinned page must be re-generated (re-drafts with
 * scoped grounding) and re-published (§2 is idempotent by ULID → overwrites the same post) to
 * correct what's live. The command warns when a target already has a wp_post_id.
 */
class BackfillPageServiceCommand extends Command
{
    protected $signature = 'launchpad:backfill-page-service
        {content? : a specific page Content id to pin}
        {--site= : backfill every unpinned service page of this site id}
        {--service= : force this Service id (only with a {content} argument)}
        {--dry-run : report what would change without writing}';

    protected $description = 'Pin primary_service_id on existing service pages so grounding scopes to each page\'s own service. One page (optionally --service forced) or a whole --site; --dry-run to preview.';

    public function handle(GuidedEntityProjector $projector): int
    {
        $pages = $this->targetPages();
        if ($pages === null) {
            return self::FAILURE;
        }

        if ($pages->isEmpty()) {
            $this->info('No unpinned service pages found — nothing to backfill.');

            return self::SUCCESS;
        }

        $forcedServiceId = $this->option('service');
        $dryRun = (bool) $this->option('dry-run');

        $pinned = 0;
        $unresolved = 0;
        foreach ($pages as $page) {
            $service = $forcedServiceId !== null
                ? $this->forcedService($page, (string) $forcedServiceId)
                : $this->resolveService($page, $projector);

            if ($service === null) {
                $this->warn("• {$page->id} \"{$page->title}\" — could not resolve a service (no spoke link or projection); skipped. Use --service= to force.");
                $unresolved++;

                continue;
            }

            if (! $dryRun) {
                $page->forceFill(['primary_service_id' => $service->id])->save();
            }

            $verb = $dryRun ? 'would pin' : 'pinned';
            $this->line("• {$page->id} \"{$page->title}\" → {$verb} service \"{$service->name}\" ({$service->id}).");

            if ($page->wp_post_id !== null) {
                $this->warn("  ↳ live on WordPress (wp_post_id={$page->wp_post_id}); re-generate then re-publish to correct what's live.");
            }

            $pinned++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '')."Pinned {$pinned} page(s); {$unresolved} unresolved.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Content>|null
     */
    private function targetPages(): ?Collection
    {
        $contentId = $this->argument('content');
        $siteId = $this->option('site');

        if ($contentId !== null) {
            $page = Content::withoutGlobalScope(SiteScope::class)
                ->where('kind', ContentKind::Page->value)
                ->find($contentId);

            if ($page === null) {
                $this->error("No page Content with id [{$contentId}] (or it is not kind=page).");

                return null;
            }

            return new Collection([$page]);
        }

        if ($this->option('service') !== null) {
            $this->error('--service= forces one page — provide the {content} id too.');

            return null;
        }

        if ($siteId !== null) {
            // Only actual Service pages get a subject pin; hub/category pages span the silo by design.
            return Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->where('kind', ContentKind::Page->value)
                ->where('page_type', PageType::Service->value)
                ->whereNull('primary_service_id')
                ->get();
        }

        $this->error('Provide a {content} id or --site=.');

        return null;
    }

    /** The §1 Service for a page, via its BuildPage → source spoke (the materialize path). */
    private function resolveService(Content $page, GuidedEntityProjector $projector): ?Service
    {
        $site = Site::withoutGlobalScopes()->find($page->site_id);
        if ($site === null) {
            return null;
        }

        $spokeId = BuildPage::query()
            ->where('content_id', $page->id)
            ->value('spoke_id');

        return $projector->serviceForSpoke($spokeId !== null ? (string) $spokeId : null, $site);
    }

    /** Force-pin a specific Service, validating it belongs to the page's site. */
    private function forcedService(Content $page, string $serviceId): ?Service
    {
        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->find($serviceId);
    }
}
