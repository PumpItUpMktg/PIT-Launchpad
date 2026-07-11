<?php

namespace App\Console\Commands;

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PageGenerator;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Spoke;
use Illuminate\Console\Command;

/**
 * Generate the SERVICE PAGE for a Spoke — unified hub/spoke entrypoint (auto-detect: a pillar spoke
 * is its silo's HUB page; anything else is a SPOKE conversion page), mirroring
 * launchpad:generate-location's shape. Resolves the spoke's MATERIALIZED page (BuildPage.content_id
 * — pages are created by the Grow build, so this command never invents one) and drives the shared
 * synchronous PageGenerator path.
 *
 * Guards (actionable failures, never thin drafts):
 *  - a SPOKE needs a name, a keyword (primary else head), and a hub (its silo membership);
 *  - a HUB needs ≥1 materialized child spoke page (a hub with nothing to route to isn't a hub).
 */
class GenerateServiceCommand extends Command
{
    protected $signature = 'launchpad:generate-service {spoke : a Spoke id (a pillar spoke generates its hub page)}';

    protected $description = 'Generate the hub or spoke service page for a Spoke — drafts (Sonnet) + renders (fal) → review queue.';

    public function handle(PageGenerator $generator): int
    {
        $spoke = Spoke::withoutGlobalScope(SiteScope::class)->find($this->argument('spoke'));
        if ($spoke === null) {
            $this->error('Spoke not found.');

            return self::FAILURE;
        }

        $keyword = trim((string) ($spoke->primary_keyword ?? $spoke->head_keyword ?? ''));
        if (trim((string) $spoke->name) === '' || $keyword === '') {
            $this->error('This spoke is missing a name or a keyword — nothing honest to target.');
            $this->line('Fix: set the spoke\'s primary keyword on the Structure screen, then re-run.');

            return self::FAILURE;
        }

        if (! $spoke->is_pillar && trim((string) $spoke->silo) === '') {
            $this->error('This spoke has no hub (no silo membership) — a spoke page needs its silo spine.');
            $this->line('Fix: assign the spoke to a silo on the Structure screen, then re-run.');

            return self::FAILURE;
        }

        $page = $this->materializedPage($spoke);
        if ($page === null) {
            $this->error('This spoke has no materialized page yet.');
            $this->line('Fix: approve the build on the Grow workbench (materialize creates the page rows), then re-run.');

            return self::FAILURE;
        }

        // A hub routes — it needs at least one child spoke page to route to.
        if ($spoke->is_pillar && ! $this->hasChildSpokePage($page)) {
            $this->error('This hub has no materialized child spoke pages — a hub with nothing to route to isn\'t a hub.');
            $this->line('Fix: add ≥1 service spoke to the silo and approve the build, then re-run.');

            return self::FAILURE;
        }

        $this->line(sprintf('%s page: %s (%s)', $spoke->is_pillar ? 'Hub' : 'Spoke', $page->title, $page->id));

        try {
            $result = $generator->generate($page);
            $this->info(sprintf("Generated '%s' → %s.", $result->title, $result->status->value));
        } catch (DraftFailedException $e) {
            $this->error(sprintf("Failed '%s' — %s", $page->title ?? $page->id, $e->getMessage()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** The spoke's materialized Content page (via its BuildPage link), or null. */
    private function materializedPage(Spoke $spoke): ?Content
    {
        $contentId = BuildPage::query()
            ->withoutGlobalScopes()
            ->where('site_id', $spoke->site_id)
            ->where('spoke_id', $spoke->id)
            ->whereNotNull('content_id')
            ->value('content_id');
        if ($contentId === null) {
            return null;
        }

        return Content::withoutGlobalScope(SiteScope::class)->find($contentId);
    }

    /** Whether the hub page's silo has ≥1 materialized child SERVICE page to route to. */
    private function hasChildSpokePage(Content $hubPage): bool
    {
        if ($hubPage->silo_id === null) {
            return false;
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $hubPage->site_id)
            ->where('silo_id', $hubPage->silo_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Service->value)
            ->exists();
    }
}
