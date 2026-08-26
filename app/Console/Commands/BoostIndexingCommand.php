<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Links\IndexBooster;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Operator-run indexing accelerator: add a controlled "Related" link to each of a site's NEWLY-published,
 * not-yet-indexed pages from a few ALREADY-INDEXED pages, then re-push those sources so Google follows the
 * new crawl path (see {@see IndexBooster}). Nothing runs automatically — this is the explicit trigger.
 *
 * PREVIEW BY DEFAULT is not the posture here (the injection is idempotent and reversible), but --dry-run
 * reports the plan (which new pages would be linked, and from how many sources) without editing or
 * re-pushing anything.
 */
class BoostIndexingCommand extends Command
{
    protected $signature = 'launchpad:boost-indexing {site : Site id, brand name, or domain (partial ok)}
        {--dry-run : Report the plan (targets + sources) without injecting links or re-pushing}';

    protected $description = 'Link newly-published, unindexed pages from already-indexed pages to speed their indexing.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return self::FAILURE;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return self::FAILURE;
        }

        /** @var Site $site */
        $site = $matches->first();
        $apply = ! $this->option('dry-run');

        $r = app(IndexBooster::class)->boost($site, $apply);

        $this->line(sprintf(
            '<info>%s</info> — %d new unindexed page(s), %d indexed source(s) available.',
            $site->brand_name, $r['targets'], $r['sources_available'],
        ));

        foreach ($r['details'] as $d) {
            $this->line("  <comment>{$d['path']}</comment> ← ".count($d['sources']).' source(s)  ('.$d['target'].')');
        }

        if ($r['links'] === 0) {
            $this->comment($r['targets'] === 0
                ? 'No newly-published unindexed pages in the window — nothing to boost.'
                : 'No new links to add (no indexed sources, or the new pages are already linked).');

            return self::SUCCESS;
        }

        $this->newLine();
        if ($apply) {
            $this->info("Added {$r['links']} inbound link(s); re-pushing {$r['sources_repushed']} source page(s) (idempotent by ULID). IndexNow pings on each re-push.");
        } else {
            $this->comment("Dry run — would add {$r['links']} inbound link(s). Re-run without --dry-run to apply.");
        }

        return self::SUCCESS;
    }
}
