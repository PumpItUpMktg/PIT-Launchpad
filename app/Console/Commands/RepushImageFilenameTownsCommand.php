<?php

namespace App\Console\Commands;

use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Seo\ImageFilenameTownReport;
use App\Publishing\TenantStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Rename rendered image files that carry the WRONG town on a location page — the residual left after the
 * alt/title/caption rewrite: the R2 key "…/sump-pump-repair-allentown-pa.jpg" on a Neptune page, reaching the
 * page as img src, srcset, og:image and the schema ImageObject url. The bytes are fine; only the name is
 * wrong. Each affected object (and its responsive variants) is COPIED to the town-correct key on the same
 * bucket, the render job is repointed (r2_key / variants / seo_filename), and the page is re-pushed so the
 * new URLs ship. The old objects are left in place — the live page references them until the repush lands.
 *
 * REPORT-ONLY by default: prints every affected job as current → proposed for review. `--execute` copies,
 * repoints and queues the repush in throttled waves. Idempotent: a repointed job no longer matches.
 */
class RepushImageFilenameTownsCommand extends Command
{
    protected $signature = 'launchpad:repush-image-filename-towns
        {--site= : limit to one site id (default: all sites)}
        {--execute : copy each object to the town-correct key, repoint the render job and re-push (default: report, change nothing)}';

    protected $description = 'Rename rendered image files that name the wrong town on location pages to the authoritative town (R2 copy + repoint), then re-push. Report-only unless --execute.';

    /** Throttle: PublishContent dispatched in waves so the client WordPress never sees a burst. */
    private const CHUNK = 10;

    private const INTERVAL_SECONDS = 15;

    public function handle(ImageFilenameTownReport $report): int
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
            $this->info('No image filename names a wrong town — nothing to rename.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('execute')) {
            $this->newLine();
            $this->comment('READ-ONLY — nothing changed. Re-run with --execute to copy, repoint and re-push.');

            return self::SUCCESS;
        }

        $this->newLine();
        $renamed = 0;
        $skipped = 0;
        $queued = 0;
        foreach ($entries as $entry) {
            [$r, $s, $q] = $this->apply($entry['rows']);
            $renamed += $r;
            $skipped += $s;
            $queued += $q;
        }
        $this->info(sprintf('Renamed %d image(s) + queued %d PublishContent job(s) (idempotent by ULID; old objects kept).', $renamed, $queued));
        if ($skipped > 0) {
            $this->warn(sprintf('%d image(s) skipped — source object missing on the bucket (see warnings above).', $skipped));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry  one site's {@see ImageFilenameTownReport::forSite()} result
     */
    private function renderSite(array $entry): void
    {
        /** @var Site $site */
        $site = $entry['site'];

        $this->newLine();
        $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
        $this->line(sprintf(
            '  %d published location page(s) · %d anchored / %d un-anchored · %d image(s) scanned against %d known place(s) · %d file(s) name a wrong town across %d page(s)',
            $entry['pages'],
            $entry['anchored'],
            $entry['unanchored'],
            $entry['jobs'],
            $entry['known_places'],
            count($entry['rows']),
            $entry['affected_pages'],
        ));

        if ($entry['rows'] === []) {
            return;
        }

        $this->table(
            ['Slug', 'Slot', 'Current', 'Proposed', 'Variants'],
            array_map(fn (array $r): array => [
                $r['slug'], $r['slot'], basename($r['current']), basename($r['proposed']), count($r['variants']),
            ], $entry['rows']),
        );

        if ($entry['unanchored'] > 0) {
            $this->comment(sprintf('  %d un-anchored page(s) skipped (no authoritative town) — run launchpad:anchor-town-pages to resolve them.', $entry['unanchored']));
        }
    }

    /**
     * Copy each object to its proposed key, repoint the render job, then re-push every affected page once.
     *
     * @param  list<array{content_id: string, slug: string, auth: string, job_id: string, slot: string, current: string, proposed: string, variants: array<string, array{0: string, 1: string}>}>  $rows
     * @return array{0: int, 1: int, 2: int} [images renamed, images skipped, pages queued]
     */
    private function apply(array $rows): array
    {
        $disk = Storage::disk(TenantStorage::DISK);
        $renamed = 0;
        $skipped = 0;
        $affected = [];

        foreach ($rows as $row) {
            $job = RenderJob::withoutGlobalScope(SiteScope::class)->find($row['job_id']);
            if ($job === null) {
                continue;
            }

            $newKey = $row['current'];
            if ($row['proposed'] !== $row['current']) {
                if (! $disk->exists($row['current']) || ! $disk->copy($row['current'], $row['proposed'])) {
                    $this->warn(sprintf('  %s [%s]: source %s missing or copy failed — skipped.', $row['slug'], $row['slot'], $row['current']));
                    $skipped++;

                    continue;
                }
                $newKey = $row['proposed'];
            }

            $variants = is_array($job->variants) ? $job->variants : [];
            foreach ($row['variants'] as $width => [$old, $new]) {
                if ($disk->exists($old) && $disk->copy($old, $new)) {
                    $variants[$width] = $new;
                } else {
                    $this->warn(sprintf('  %s [%s]: variant %s missing or copy failed — kept.', $row['slug'], $row['slot'], $old));
                }
            }

            $seoFilename = $job->seo_filename;
            if (is_string($seoFilename) && $seoFilename !== '' && $newKey !== $row['current']) {
                $seoFilename = basename($newKey);
            }

            $job->forceFill([
                'r2_key' => $newKey,
                'variants' => $variants !== [] ? $variants : null,
                'seo_filename' => $seoFilename,
            ])->save();
            $renamed++;
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

        return [$renamed, $skipped, $queued];
    }
}
