<?php

namespace App\Console\Commands;

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PageGenerator;
use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Generate page content for a single kind=page Content row, or — with --site —
 * every undrafted page of a site, sequentially. Still one explicit, operator-
 * invoked command (never scheduled/auto). Synchronous: there is no FPM clock on
 * the console, and it reports each page's outcome inline.
 */
class GeneratePageCommand extends Command
{
    protected $signature = 'launchpad:generate-page {content? : a kind=page Content id} {--site= : draft every undrafted page of this site id, sequentially}';

    protected $description = 'Generate page content (kit slots + images) for a page, or all undrafted pages of a --site. Drafts (Sonnet) + renders (fal) → review queue.';

    public function handle(PageGenerator $generator): int
    {
        $pages = $this->resolvePages();

        if ($pages === null) {
            return self::FAILURE;
        }

        if ($pages->isEmpty()) {
            $this->info('No undrafted pages to generate.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($pages as $page) {
            try {
                $result = $generator->generate($page);
                $this->info(sprintf("Generated '%s' → %s.", $result->title, $result->status->value));
            } catch (DraftFailedException $e) {
                $failed++;
                $this->error(sprintf("Failed '%s' — %s", $page->title ?? $page->id, $e->getMessage()));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, Content>|null null when the inputs are invalid
     */
    private function resolvePages(): ?Collection
    {
        $siteId = $this->option('site');

        if ($siteId !== null) {
            return Content::query()
                ->withoutGlobalScope(SiteScope::class)
                ->where('site_id', (string) $siteId)
                ->where('kind', ContentKind::Page->value)
                ->get()
                ->reject(fn (Content $page) => $page->hasDraft())
                ->values();
        }

        $id = $this->argument('content');
        if ($id === null) {
            $this->error('Provide a Content id or --site=<id>.');

            return null;
        }

        $page = Content::query()->withoutGlobalScope(SiteScope::class)->find($id);
        if ($page === null) {
            $this->error('Page not found.');

            return null;
        }

        if ($page->kind !== ContentKind::Page) {
            $this->error('That Content is not a page (use launchpad:generate-post for posts).');

            return null;
        }

        return new Collection([$page]);
    }
}
