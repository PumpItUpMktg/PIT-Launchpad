<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Seo\LocationTitle;
use App\Publishing\Seo\LocationTitleReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Correct every published location page's title to the DETERMINISTIC "{Trade} in {Town}, {ST}" — composed
 * from the page's authoritative structured subject (a hub's Location, a town's geo_id → CoverageArea), not
 * the drafter-authored (sometimes hallucinated) stored title. On --execute it writes the deterministic title
 * into stored meta.seo.title (cleaning the hallucination out of storage) and re-pushes so the live <title>
 * refreshes — idempotent by ULID, no re-draft, no fal spend, published_at preserved.
 *
 * REPORT-ONLY by default: prints the COMPUTED wrong-town census (the town the stored title names vs. the
 * authoritative town), the missing-town count, the anchored/un-anchored split, the composed <title> each
 * page will render + how many still exceed 60, and the drafted-H1 mismatch count (LLM prose — surfaced,
 * never rewritten here). Un-anchored town pages (no geo_id) are skipped, NOT laundered through a name guess
 * — run launchpad:anchor-town-pages first. `--site=` limits to one tenant.
 */
class RepushLocationTitlesCommand extends Command
{
    protected $signature = 'launchpad:repush-location-titles
        {--site= : limit to one site id (default: all sites)}
        {--execute : backfill the deterministic title into stored meta.seo.title and re-push (default: report, change nothing)}';

    protected $description = 'Correct published location-page titles to the deterministic "{Trade} in {Town}, {ST}" from the authoritative town, then re-push. Report-only unless --execute.';

    /** Throttle: PublishContent dispatched in waves so the client WordPress never sees a burst. */
    private const CHUNK = 10;

    private const INTERVAL_SECONDS = 15;

    public function handle(LocationTitleReport $report, LocationTitle $composer): int
    {
        $siteId = $this->option('site');
        if ($siteId !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }
            $entry = $report->forSite($site);
            $sites = $entry['total'] > 0 ? [$entry] : [];
        } else {
            $sites = $report->report();
        }

        if ($sites === []) {
            $this->info('No published location pages found.');

            return self::SUCCESS;
        }

        foreach ($sites as $entry) {
            $this->renderSite($entry);
        }

        $execute = (bool) $this->option('execute');
        if (! $execute) {
            $this->newLine();
            $this->comment('READ-ONLY — nothing changed. Re-run with --execute to backfill the titles and re-push.');

            return self::SUCCESS;
        }

        $this->newLine();
        $dispatched = 0;
        foreach ($sites as $entry) {
            $dispatched += $this->backfillSite($entry['site'], $composer, $entry['pages']);
        }
        $this->info(sprintf('Backfilled + queued %d PublishContent job(s) — only the anchored location pages whose title or H1 changes (idempotent by ULID; un-anchored and already-correct pages skipped).', $dispatched));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry  one site's {@see LocationTitleReport::forSite()} result
     */
    private function renderSite(array $entry): void
    {
        /** @var Site $site */
        $site = $entry['site'];

        $this->newLine();
        $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
        $this->line(sprintf(
            '  %d published location page(s) · %d anchored / %d un-anchored · %d wrong-town · %d missing-town · %d H1-mismatch · %d render <title> over %d · %d would be re-pushed',
            $entry['total'],
            $entry['anchored'],
            $entry['unanchored'],
            $entry['wrong_town'],
            $entry['missing_town'],
            $entry['h1_mismatch'],
            $entry['over'],
            LocationTitleReport::LIMIT,
            $entry['needs_push'],
        ));

        // The actionable rows: pages whose stored title names the wrong town or no town, and pages whose
        // drafted H1 does not name the authoritative town (the render yields to the formula; a push applies it).
        $rows = [];
        foreach ($entry['pages'] as $p) {
            if ($p['status'] !== 'wrong_town' && $p['status'] !== 'missing_town' && ! $p['h1_mismatch']) {
                continue;
            }
            $rows[] = [
                $p['status'] === 'wrong_town' ? 'WRONG' : ($p['status'] === 'missing_town' ? 'MISSING' : 'H1'),
                $p['slug'],
                $p['stored_town'] !== '' ? $p['stored_town'] : '—',
                $p['auth_town'],
                $p['title'],
            ];
        }
        if ($rows !== []) {
            $this->table(['Defect', 'Slug', 'Stored town', 'Authoritative', 'New <title> (as it renders)'], $rows);
        }

        if ($entry['unanchored'] > 0) {
            $this->comment(sprintf('  %d un-anchored page(s) skipped (no geo_id) — run launchpad:anchor-town-pages to resolve them.', $entry['unanchored']));
        }
    }

    /**
     * Backfill + re-push one site's ANCHORED published location pages. Writes only meta.seo.title (rest of
     * meta preserved), dispatched in throttled waves. Returns the number of pages touched.
     */
    /**
     * @param  list<array{slug: string, needs_push: bool, ...}>  $pages  the site's census rows — only `needs_push` pages are touched
     */
    private function backfillSite(Site $site, LocationTitle $composer, array $pages): int
    {
        $wanted = [];
        foreach ($pages as $p) {
            if ($p['needs_push']) {
                $wanted[$p['slug']] = true;
            }
        }

        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->orderBy('published_at')
            ->get();

        $touched = 0;
        foreach ($rows as $content) {
            if (! isset($wanted[(string) $content->slug])) {
                continue; // already carries the deterministic title and a correct H1 — nothing to push
            }
            $title = $composer->compose($content);
            if ($title === null) {
                continue; // un-anchored — never launder a bad title through a name guess
            }

            $meta = is_array($content->meta) ? $content->meta : [];
            $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];
            $seo['title'] = $title;
            $meta['seo'] = $seo;
            $content->forceFill(['meta' => $meta])->save();

            PublishContent::dispatch((string) $content->id)
                ->delay(Carbon::now()->addSeconds(intdiv($touched, self::CHUNK) * self::INTERVAL_SECONDS));
            $touched++;
        }

        return $touched;
    }
}
