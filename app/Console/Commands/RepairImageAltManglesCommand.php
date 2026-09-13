<?php

namespace App\Console\Commands;

use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Seo\ImageAltMangleRepair;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Undo the three mis-rewrites an earlier launchpad:repush-image-alt-towns run wrote before its guards were
 * fixed (#841): a state list read as a town ("Belleville, NJ, and Maryland"), a region's last word swapped
 * for the town ("Lehigh Howell"), and a doubled own town ("Upper Upper Darby"). Each is reversed
 * deterministically ({@see ImageAltMangleRepair}) and the page re-pushed. REPORT-ONLY by default.
 */
class RepairImageAltManglesCommand extends Command
{
    protected $signature = 'launchpad:repair-image-alt-mangles
        {--site= : limit to one site id (default: all sites)}
        {--execute : write the repaired render-job fields and re-push (default: report, change nothing)}';

    protected $description = 'Undo the state-list / region / doubled-town mis-rewrites in image alt/title/caption, then re-push. Report-only unless --execute.';

    private const CHUNK = 10;

    private const INTERVAL_SECONDS = 15;

    public function handle(ImageAltMangleRepair $repair): int
    {
        $siteId = $this->option('site');
        if ($siteId !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }
            $entries = [$repair->forSite($site)];
        } else {
            $entries = [];
            foreach (Site::withoutGlobalScopes()->orderBy('brand_name')->get() as $site) {
                $entry = $repair->forSite($site);
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
            $this->info('No mangled image alt/title/caption found — nothing to repair.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('execute')) {
            $this->newLine();
            $this->comment('READ-ONLY — nothing changed. Re-run with --execute to repair these and re-push.');

            return self::SUCCESS;
        }

        $this->newLine();
        $repaired = 0;
        $queued = 0;
        foreach ($entries as $entry) {
            [$r, $q] = $this->apply($entry['rows']);
            $repaired += $r;
            $queued += $q;
        }
        $this->info(sprintf('Repaired %d field(s) + queued %d PublishContent job(s) (idempotent by ULID).', $repaired, $queued));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry  one site's {@see ImageAltMangleRepair::forSite()} result
     */
    private function renderSite(array $entry): void
    {
        /** @var Site $site */
        $site = $entry['site'];

        $this->newLine();
        $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
        $this->line(sprintf(
            '  %d published location page(s) · %d anchored / %d un-anchored · %d mangled field(s) across %d page(s)',
            $entry['pages'],
            $entry['anchored'],
            $entry['unanchored'],
            count($entry['rows']),
            $entry['affected_pages'],
        ));

        if ($entry['rows'] !== []) {
            $this->table(
                ['Slug', 'Slot', 'Field', 'Current', 'Proposed'],
                array_map(fn (array $r): array => [$r['slug'], $r['slot'], $r['field'], $r['current'], $r['proposed']], $entry['rows']),
            );
        }

        foreach ($entry['unresolved'] as $u) {
            $this->warn(sprintf('  UNRESOLVED %s [%s]: more than one site state could fill the list — left as-is: %s', $u['slug'], $u['field'], $u['current']));
        }
    }

    /**
     * @param  list<array{content_id: string, slug: string, auth: string, job_id: string, slot: string, field: string, current: string, proposed: string}>  $rows
     * @return array{0: int, 1: int} [fields repaired, pages queued]
     */
    private function apply(array $rows): array
    {
        $repaired = 0;
        $affected = [];

        foreach ($rows as $row) {
            $job = RenderJob::withoutGlobalScope(SiteScope::class)->find($row['job_id']);
            if ($job === null) {
                continue;
            }
            $job->forceFill([$row['field'] => $row['proposed']])->save();
            $repaired++;
            $affected[$row['content_id']] = true;
        }

        $queued = 0;
        foreach (array_keys($affected) as $contentId) {
            $content = Content::withoutGlobalScope(SiteScope::class)->find($contentId);
            if ($content === null || $content->wp_post_id === null) {
                continue;
            }
            PublishContent::dispatch((string) $content->id)
                ->delay(Carbon::now()->addSeconds(intdiv($queued, self::CHUNK) * self::INTERVAL_SECONDS));
            $queued++;
        }

        return [$repaired, $queued];
    }
}
