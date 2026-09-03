<?php

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Publishing\MetaBlobAssembler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Re-push the PUBLISHED content that carries a silo breadcrumb so its BAKED trail is regenerated with the
 * corrected middle-crumb resolution (MetaBlobAssembler now links the silo's live top page — pillar, Hub,
 * Service, or any live page — and DROPS an unresolvable crumb rather than emit a name without a URL).
 * Breadcrumbs live in the pushed payload's `seo.breadcrumbs`, so the fix reaches an already-live URL only
 * on a re-push; this queues one idempotent PublishContent per affected item.
 *
 * Affected = every PUBLISHED item that receives a silo crumb: in a silo, not a Hub (the silo head), and not
 * its silo's own pillar (self-referential crumb, collapsed). This covers BOTH `kind=page` service/pillar
 * pages AND `kind=post` blog posts (a post has `page_type=null`, so it is not excluded by the Hub filter).
 *
 * Reports the resolution split BEFORE touching anything: per silo, how many affected items would emit a
 * valid THREE-item crumb (the silo resolves to a live top page) vs a valid TWO-item Home → leaf (the silo
 * has no live top page — still valid markup, just shallower). This is report-only by default; pass
 * `--execute` to actually queue the re-push. `--site=` limits to one tenant.
 */
class RepushBreadcrumbsCommand extends Command
{
    protected $signature = 'launchpad:repush-breadcrumbs
        {--site= : limit to one site id}
        {--execute : queue the re-push (default: report the resolution split, change nothing)}';

    protected $description = 'Re-push published pages/posts carrying a silo breadcrumb so their baked trail picks up the corrected middle-crumb resolution. Report-only unless --execute; reports the 3-item vs 2-item split per silo.';

    public function handle(MetaBlobAssembler $assembler): int
    {
        $siteId = $this->option('site');
        $execute = (bool) $this->option('execute');

        $pillarIds = Silo::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->whereNotNull('pillar_content_id')
            ->pluck('pillar_content_id')
            ->map('strval')
            ->flip();

        $affected = Content::withoutGlobalScope(SiteScope::class)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('silo_id')
            // A post has page_type=null (not excluded by the Hub filter); a page must not be a Hub.
            ->where(fn ($q) => $q->whereNull('page_type')->orWhere('page_type', '!=', PageType::Hub->value))
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->with('silo')
            ->get()
            ->reject(fn (Content $c) => $pillarIds->has((string) $c->id))
            ->values();

        if ($affected->isEmpty()) {
            $this->info('No published pages or posts carry a silo breadcrumb — nothing to re-push.');

            return self::SUCCESS;
        }

        $siloNames = Silo::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $affected->pluck('silo_id')->unique()->all())
            ->pluck('name', 'id');

        $threeItem = 0;
        $twoItem = 0;
        foreach ($affected->groupBy('silo_id') as $siloId => $group) {
            /** @var Collection<int, Content> $group */
            $resolves = $group->filter(fn (Content $c): bool => $assembler->resolvesSiloTop($c))->count();
            $drops = $group->count() - $resolves;
            $threeItem += $resolves;
            $twoItem += $drops;

            $this->line(sprintf(
                '• %-40s %3d affected  →  %d 3-item, %d 2-item%s',
                (string) ($siloNames[$siloId] ?? $siloId),
                $group->count(),
                $resolves,
                $drops,
                $resolves === 0 ? '  ⚠ no live top page in this silo' : '',
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%d affected item(s) across %d silo(s): %d would emit a valid 3-item crumb, %d a valid 2-item (silo has no live top page).',
            $affected->count(),
            $affected->groupBy('silo_id')->count(),
            $threeItem,
            $twoItem,
        ));

        if (! $execute) {
            $this->comment('Report only — nothing re-pushed. Re-run with --execute to queue the re-push.');

            return self::SUCCESS;
        }

        foreach ($affected as $content) {
            PublishContent::dispatch((string) $content->id);
        }
        $this->info(sprintf('Queued %d PublishContent job(s) (idempotent by ULID).', $affected->count()));

        return self::SUCCESS;
    }
}
