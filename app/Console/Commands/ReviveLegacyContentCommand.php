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
        $this->line(sprintf('  %d family(ies) to revive as blog candidates (numbered dups grouped):', count($plan)));
        foreach ($plan as $row) {
            $n = count($row['from_urls']);
            $primary = $row['from_urls'][0] ?? '—';
            $extra = $n > 1 ? " (+{$n} URLs)" : '';
            $this->line(sprintf('    %8s  %s%s  →  “%s”', number_format($row['impressions']), $primary, $extra, $row['query'] ?? '—'));
        }

        if ($plan === []) {
            $this->explainEmpty($reviver->diagnose($site, $floor, $limit));

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

    /**
     * Say which filter emptied the plan, not that one of several might have.
     *
     * @param  array{unresolved: int, divertable: int, claimed: int, families: int, below_floor: int, below_floor_impressions: int, below_floor_bands: array<string, array{families: int, impressions: int}>, below_divert_floor: int, capped: int, floor: int, divert_floor: int, cap: int}  $s
     */
    private function explainEmpty(array $s): void
    {
        $this->newLine();
        $this->line(sprintf('  Pool: <info>%d</info> unresolved + <info>%d</info> divertable URL(s) → <info>%d</info> family(ies).',
            $s['unresolved'], $s['divertable'], $s['families']));
        $this->line(sprintf('  Already claimed by an earlier revival: <info>%d</info> candidate(s).', $s['claimed']));

        if ($s['cap'] < 1) {
            $this->error(sprintf('  The per-run limit is %d, so nothing can ever be returned. Pass --limit= or raise LAUNCHPAD_REVIVE_LIMIT.', $s['cap']));

            return;
        }
        if ($s['families'] === 0) {
            $this->comment($s['claimed'] > 0
                ? '  Every candidate URL is already claimed by a revival — there is nothing left to revive.'
                : '  The redirect planner returned nothing revivable. Run launchpad:plan-legacy-redirects to see why.');

            return;
        }
        if ($s['below_floor'] > 0) {
            $this->comment(sprintf('  %d family(ies) fell below the %s-impression floor, carrying %s impression(s) between them.',
                $s['below_floor'], number_format($s['floor']), number_format($s['below_floor_impressions'])));
            // A count alone cannot be acted on: 274 families is 274 x 50 impressions or 274 x 4,900, and
            // those are opposite answers to "is it worth lowering the floor".
            foreach ($s['below_floor_bands'] as $band => $stats) {
                $this->line(sprintf('     %-28s %4d family(ies), %s impression(s)',
                    $band, $stats['families'], number_format($stats['impressions'])));
            }
            $this->line('     Lower the floor with <info>--min-impressions=</info> only if a band above is worth the drafts it costs.');
        }
        if ($s['below_divert_floor'] > 0) {
            $this->comment(sprintf('  %d family(ies) matched a live page and were under the %s-impression divert floor, so they stay redirects.',
                $s['below_divert_floor'], number_format($s['divert_floor'])));
        }
    }
}
