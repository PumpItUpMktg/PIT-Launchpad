<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Publishing\Links\DeadHrefUnlinker;
use Illuminate\Console\Command;

/**
 * The other half of the dead-link backfill: the hrefs that could NOT be redirected (no live target, no
 * redirect — held-market / removed-duplicate pages) are removed from the copy, words kept, and each carrying
 * page re-published. Report-only by default; --execute unlinks and queues the repushes.
 */
class UnlinkDeadHrefsCommand extends Command
{
    protected $signature = 'launchpad:unlink-dead-hrefs
        {--site= : Limit to one site id or brand name}
        {--execute : Unwrap the dead anchors and re-publish the pages (default: report-only — changes nothing)}';

    protected $description = 'Remove hrefs whose target has no live page and no redirect from published copy (words kept), then re-push. Report-only unless --execute.';

    public function handle(DeadHrefUnlinker $unlinker): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::query()->get();
        }

        $execute = (bool) $this->option('execute');
        $this->info($execute
            ? 'EXECUTE · unlinking dead hrefs and re-publishing the carrying pages.'
            : 'Read-only · dead-href unlink PLAN. Nothing is changed (pass --execute to write).');

        $grandAnchors = 0;
        $grandPages = 0;
        foreach ($sites as $site) {
            $plan = $unlinker->plan($site);
            if ($plan['rows'] === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            $this->line(sprintf('  %d dead path(s) with no live target · %d anchor(s) across %d published page(s)', count($plan['paths']), $plan['anchors'], $plan['pages']));
            $this->table(
                ['Slug', 'Kind', 'Dead path', 'Anchors', 'Sample text'],
                array_map(fn (array $r): array => [$r['slug'], $r['kind'], $r['path'], $r['anchors'], $r['sample']], $plan['rows']),
            );
            $grandAnchors += $plan['anchors'];
            $grandPages += $plan['pages'];

            if ($execute) {
                $result = $unlinker->apply($site);
                $this->info(sprintf('  Unlinked %d anchor(s) across %d page(s); queued %d repush(es).', $result['anchors'], $result['pages'], $result['queued']));

                // Write-verification: re-plan; nothing dead should remain linked.
                $left = $unlinker->plan($site)['anchors'];
                if ($left > 0) {
                    $this->warn("  {$left} dead anchor(s) still present after re-read.");
                }
            }
        }

        $this->newLine();
        if ($grandAnchors === 0) {
            $this->info('No dead hrefs to unlink — every internal link resolves or redirects.');

            return self::SUCCESS;
        }
        if (! $execute) {
            $this->info("{$grandAnchors} dead anchor(s) across {$grandPages} page(s) would be unlinked. Re-run with --execute to write (nothing was changed).");
        }

        return self::SUCCESS;
    }
}
