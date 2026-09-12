<?php

namespace App\Console\Commands;

use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Seo\ImageAltTownReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fix image alt / title / caption text that names the WRONG town on a location page — the residual left
 * after a regenerate: the text slots were re-drafted with the correct town, but the already-rendered image
 * kept the alt the vision pass wrote under the OLD (wrong) grounding ("…in a clean Allentown, PA basement"
 * on a Neptune page). The image is generic, only the place name is wrong, so this rewrites the place
 * deterministically ({@see ImageAltTownReport}) — no fal or vision spend — then re-pushes the page so the
 * corrected alt ships. Idempotent by ULID; un-anchored pages are skipped (no authoritative town).
 *
 * REPORT-ONLY by default: prints every affected job as current → proposed so the rewrite is reviewed
 * before it is written. `--execute` writes the render-job fields and queues the repush in throttled waves.
 */
class RepushImageAltTownsCommand extends Command
{
    protected $signature = 'launchpad:repush-image-alt-towns
        {--site= : limit to one site id (default: all sites)}
        {--execute : rewrite the render-job alt/title/caption and re-push (default: report, change nothing)}';

    protected $description = 'Rewrite image alt/title/caption that names the wrong town on location pages to the authoritative town, then re-push. Report-only unless --execute.';

    /** Throttle: PublishContent dispatched in waves so the client WordPress never sees a burst. */
    private const CHUNK = 10;

    private const INTERVAL_SECONDS = 15;

    public function handle(ImageAltTownReport $report): int
    {
        $siteId = $this->option('site');
        if ($siteId !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }
            $entries = [$report->forSite($site)];
        } else {
            $entries = [];
            foreach (Site::withoutGlobalScopes()->orderBy('brand_name')->get() as $site) {
                $entry = $report->forSite($site);
                if ($entry['pages'] > 0) {
                    $entries[] = $entry;
                }
            }
        }

        if ($entries === []) {
            $this->info('No published location pages found.');

            return self::SUCCESS;
        }

        $totalRows = 0;
        foreach ($entries as $entry) {
            $this->renderSite($entry);
            $totalRows += count($entry['rows']);
        }

        if ($totalRows === 0) {
            $this->info('No image alt/title/caption names a wrong town — nothing to rewrite.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('execute')) {
            $this->newLine();
            $this->comment('READ-ONLY — nothing changed. Re-run with --execute to rewrite these and re-push.');

            return self::SUCCESS;
        }

        $this->newLine();
        $rewritten = 0;
        $queued = 0;
        foreach ($entries as $entry) {
            [$r, $q] = $this->apply($entry['rows']);
            $rewritten += $r;
            $queued += $q;
        }
        $this->info(sprintf('Rewrote %d field(s) + queued %d PublishContent job(s) (idempotent by ULID; un-anchored skipped).', $rewritten, $queued));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry  one site's {@see ImageAltTownReport::forSite()} result
     */
    private function renderSite(array $entry): void
    {
        /** @var Site $site */
        $site = $entry['site'];

        $this->newLine();
        $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
        $this->line(sprintf(
            '  %d published location page(s) · %d anchored / %d un-anchored · %d field(s) name a wrong town across %d page(s)',
            $entry['pages'],
            $entry['anchored'],
            $entry['unanchored'],
            count($entry['rows']),
            $entry['affected_pages'],
        ));

        if ($entry['rows'] === []) {
            return;
        }

        $this->table(
            ['Slug', 'Slot', 'Field', 'Current', 'Proposed'],
            array_map(fn (array $r): array => [$r['slug'], $r['slot'], $r['field'], $r['current'], $r['proposed']], $entry['rows']),
        );

        if ($entry['unanchored'] > 0) {
            $this->comment(sprintf('  %d un-anchored page(s) skipped (no authoritative town) — run launchpad:anchor-town-pages to resolve them.', $entry['unanchored']));
        }
    }

    /**
     * Write each proposed rewrite to its render job, then re-push every affected page once (throttled).
     *
     * @param  list<array{content_id: string, slug: string, auth: string, job_id: string, slot: string, field: string, current: string, proposed: string}>  $rows
     * @return array{0: int, 1: int} [fields rewritten, pages queued]
     */
    private function apply(array $rows): array
    {
        $rewritten = 0;
        $affected = [];

        foreach ($rows as $row) {
            $job = RenderJob::withoutGlobalScope(SiteScope::class)->find($row['job_id']);
            if ($job === null) {
                continue;
            }
            $job->forceFill([$row['field'] => $row['proposed']])->save();
            $rewritten++;
            $affected[$row['content_id']] = true;
        }

        $queued = 0;
        foreach (array_keys($affected) as $contentId) {
            $content = Content::withoutGlobalScope(SiteScope::class)->find($contentId);
            if ($content === null || $content->wp_post_id === null) {
                continue; // never published — nothing on WordPress to refresh
            }
            PublishContent::dispatch((string) $content->id)
                ->delay(Carbon::now()->addSeconds(intdiv($queued, self::CHUNK) * self::INTERVAL_SECONDS));
            $queued++;
        }

        return [$rewritten, $queued];
    }
}
