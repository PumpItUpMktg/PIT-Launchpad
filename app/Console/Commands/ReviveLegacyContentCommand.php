<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Redirects\LegacyContentReviver;
use Illuminate\Console\Command;

/**
 * Seed reviewable blog candidates from the high-value UNRESOLVED legacy URLs (the
 * high-traffic informational pages the redirect planner couldn't route to a
 * successor). Each candidate carries its winning GSC query as the brief; the
 * operator generates it through the normal gated flow, and on publish the old URL
 * 301s to the new post. Dry-run by default; `--apply` creates the candidates.
 * This command NEVER drafts or generates — generation stays operator-gated.
 */
class ReviveLegacyContentCommand extends Command
{
    protected $signature = 'launchpad:revive-legacy-content {--site= : Site id or brand name (required)} {--min-impressions= : Impression floor (default config, 5000)} {--limit= : Max candidates this run (default config, 100)} {--apply : Create the candidates}';

    protected $description = 'Seed reviewable blog candidates from high-value unresolved legacy URLs (301 old→new on publish).';

    public function handle(LegacyContentReviver $reviver): int
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '') {
            $this->error('--site is required (id or brand name).');

            return self::FAILURE;
        }
        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return self::FAILURE;
        }

        $floor = $this->option('min-impressions') !== null ? max(0, (int) $this->option('min-impressions')) : null;
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $plan = $reviver->plan($site, $floor, $limit);

        $this->line("<info>{$site->brand_name}</info> — legacy content revival");
        $this->line(sprintf('  %d high-value unresolved URL(s) to revive as blog candidates:', count($plan)));
        foreach ($plan as $row) {
            $this->line(sprintf('    %8s  %s  →  “%s”', number_format($row['impressions']), $row['from'], $row['query'] ?? '—'));
        }

        if ($plan === []) {
            $this->comment('  Nothing above the impression floor (or all already revived).');

            return self::SUCCESS;
        }

        if ($this->option('apply')) {
            $created = $reviver->revive($site, $floor, $limit);
            $this->newLine();
            $this->info(sprintf('Created %d blog candidate(s). Generate them from the Blog surface — each 301s its old URL on publish.', count($created)));
        } else {
            $this->newLine();
            $this->comment('Dry run — re-run with --apply to create the candidates.');
        }

        return self::SUCCESS;
    }
}
