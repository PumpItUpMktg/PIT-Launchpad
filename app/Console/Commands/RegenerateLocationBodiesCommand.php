<?php

namespace App\Console\Commands;

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PageGenerator;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\LocationBodyReport;
use Illuminate\Console\Command;

/**
 * REGENERATE the location pages whose DRAFTED content names the wrong town — exactly the set
 * LocationBodyReport scopes. The deterministic-title fix corrects the <title>, but the drafted slots (hero
 * H1, intro, FAQ) were generated with drifted grounding and still name the wrong town; only a re-draft fixes
 * that, and now that the pages are anchored a regenerate grounds on the correct town. Each page is
 * re-drafted (Sonnet) + re-rendered (fal) and lands back in the review queue for approval — real spend, so
 * it is gated behind an explicit --execute (dry-run reports the exact set + count by default). A set must be
 * chosen (--foreign and/or --weak) so it can never regenerate every page by accident.
 */
class RegenerateLocationBodiesCommand extends Command
{
    protected $signature = 'launchpad:regenerate-location-bodies
        {--site= : limit to one site id (default: all sites)}
        {--foreign : the foreign-town pages (drafted H1 names a WRONG town) — the priority set}
        {--weak : also the town-blind H1 pages (generic/region H1, no specific wrong town)}
        {--execute : actually regenerate (Sonnet drafts + fal renders → review queue); default: dry-run}';

    protected $description = 'Regenerate the location pages whose drafted content names the wrong town (LocationBodyReport set). Dry-run unless --execute; Sonnet + fal per page.';

    public function handle(LocationBodyReport $report, PageGenerator $generator): int
    {
        $wantForeign = (bool) $this->option('foreign');
        $wantWeak = (bool) $this->option('weak');
        if (! $wantForeign && ! $wantWeak) {
            $this->error('Choose a set: --foreign (wrong-town H1) and/or --weak (town-blind H1). Refusing to regenerate every page.');

            return self::FAILURE;
        }

        $statuses = array_values(array_filter([
            $wantForeign ? 'foreign_town' : null,
            $wantWeak ? 'weak' : null,
        ]));

        if (($siteId = $this->option('site')) !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }
            $entries = [$report->forSite($site)];
        } else {
            $entries = $report->report();
        }

        /** @var list<array{id: string, slug: string, auth: string, h1: string}> $targets */
        $targets = [];
        foreach ($entries as $entry) {
            foreach ($entry['pages'] as $p) {
                if (in_array($p['status'], $statuses, true)) {
                    $targets[] = ['id' => (string) $p['id'], 'slug' => (string) $p['slug'], 'auth' => (string) $p['auth'], 'h1' => (string) $p['h1']];
                }
            }
        }

        if ($targets === []) {
            $this->info('No location pages match the selected set — nothing to regenerate.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Authoritative', 'Drafted H1'],
            array_map(fn (array $t): array => [$t['slug'], $t['auth'], $t['h1']], $targets),
        );
        $this->line(sprintf('%d location page(s) selected for regenerate.', count($targets)));

        if (! (bool) $this->option('execute')) {
            $this->comment(sprintf('DRY RUN — nothing regenerated. Re-run with --execute to re-draft these (%d× Sonnet + fal → review queue).', count($targets)));

            return self::SUCCESS;
        }

        $this->newLine();
        $failed = 0;
        foreach ($targets as $t) {
            $page = Content::withoutGlobalScope(SiteScope::class)->find($t['id']);
            if ($page === null) {
                continue;
            }
            try {
                $result = $generator->generate($page);
                $this->info(sprintf("Regenerated '%s' → %s.", $t['slug'], $result->status->value));
            } catch (DraftFailedException $e) {
                $failed++;
                $this->error(sprintf("Failed '%s' — %s", $t['slug'], $e->getMessage()));
            }
        }

        $this->newLine();
        $this->line(sprintf('Regenerated %d of %d — back in the review queue; approve to republish.', count($targets) - $failed, count($targets)));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
