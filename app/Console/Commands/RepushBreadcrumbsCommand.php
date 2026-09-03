<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use Illuminate\Console\Command;

/**
 * Re-push the pages that carry a silo breadcrumb so their BAKED trail is regenerated with the
 * corrected middle-crumb resolution (MetaBlobAssembler now links the silo's live top page and
 * drops an unresolvable crumb). Breadcrumbs are baked into the pushed payload's `seo.breadcrumbs`,
 * so the fix only reaches an already-live page on a re-push — this queues one idempotent
 * PublishContent per affected page instead of a per-post manual Repush.
 *
 * Affected = every PUBLISHED `kind=page` that receives a silo crumb: in a silo, not a Hub (the silo
 * head itself), and not its silo's own pillar (self-referential crumb, collapsed). `--dry-run`
 * reports the affected count per silo and changes nothing; `--site=` limits to one tenant.
 */
class RepushBreadcrumbsCommand extends Command
{
    protected $signature = 'launchpad:repush-breadcrumbs {--site= : limit to one site id} {--dry-run : report the affected count per silo, change nothing}';

    protected $description = 'Re-push published pages carrying a silo breadcrumb so their baked trail picks up the corrected middle-crumb resolution. --dry-run reports counts per silo.';

    public function handle(): int
    {
        $siteId = $this->option('site');
        $dryRun = (bool) $this->option('dry-run');

        $pillarIds = Silo::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->whereNotNull('pillar_content_id')
            ->pluck('pillar_content_id')
            ->map('strval')
            ->flip();

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('status', ContentStatus::Published->value)
            ->where('kind', ContentKind::Page->value)
            ->whereNotNull('silo_id')
            ->where('page_type', '!=', PageType::Hub->value)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->get(['id', 'site_id', 'silo_id'])
            ->reject(fn (Content $c) => $pillarIds->has((string) $c->id))
            ->values();

        if ($pages->isEmpty()) {
            $this->info('No published pages carry a silo breadcrumb — nothing to re-push.');

            return self::SUCCESS;
        }

        $siloNames = Silo::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $pages->pluck('silo_id')->unique()->all())
            ->pluck('name', 'id');

        $bySilo = $pages->groupBy('silo_id');
        foreach ($bySilo as $siloId => $group) {
            $this->line(sprintf('• %-44s %d page(s)', (string) ($siloNames[$siloId] ?? $siloId), $group->count()));
        }
        $this->info(sprintf('%d page(s) across %d silo(s).', $pages->count(), $bySilo->count()));

        if ($dryRun) {
            $this->comment('Dry run — nothing re-pushed. Re-run without --dry-run to queue the re-push.');

            return self::SUCCESS;
        }

        foreach ($pages as $page) {
            PublishContent::dispatch((string) $page->id);
        }
        $this->info(sprintf('Queued %d PublishContent job(s) (idempotent by ULID).', $pages->count()));

        return self::SUCCESS;
    }
}
