<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\Coverage\IndexReconciliation;
use Illuminate\Console\Command;

/**
 * Why the Indexing board and the per-page cards disagree — the two headline figures side by side, then
 * the difference broken down by cause with named examples.
 *
 *   launchpad:report-index-mismatch --site=... [--examples=20]
 *
 * Read-only and HTTP-free. This is the diagnosis, not the fix: it says which surface is counting what,
 * so the decision about which one moves is made against a real number instead of an assumption.
 */
class ReportIndexMismatchCommand extends Command
{
    protected $signature = 'launchpad:report-index-mismatch
        {--site= : Site id or brand name}
        {--examples=20 : How many example pages to list per cause}';

    protected $description = 'Reconcile the Indexing board against the per-page index chips and explain every difference.';

    public function handle(IndexReconciliation $reconciliation): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('No site found — pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $r = $reconciliation->for($site, max(1, (int) $this->option('examples')));
        $board = $r['board'];
        $cards = $r['cards'];

        $this->info($site->brand_name.' — Indexing board vs per-page chips');
        $this->newLine();

        $this->table(['Surface', 'Population', 'Indexed', 'Not indexed', 'Excluded', 'Not yet checked'], [
            ['Indexing board', $board['inspected'].' inspected', $board['indexed'], $board['not_indexed'], $board['excluded'], $board['gap'].' (shown beside, not counted in)'],
            ['Page cards', $cards['published'].' published', $cards['indexed'], $cards['not_indexed'], '—', $cards['unchecked']],
        ]);

        $this->line('Both surfaces call a page indexed on a PASS verdict <comment>or</comment> Search impressions, over the');
        $this->line('pages that are currently published. Impressions are proof a page is in the index; their');
        $this->line('absence proves nothing, so it is an OR and never an AND.');
        $this->newLine();

        if ($r['surfaces_agree']) {
            $this->line('<info>The two surfaces agree.</info>');
        } else {
            $this->warn('The two surfaces still differ — a cause this report does not yet name.');
        }

        $this->newLine();
        $this->line(sprintf('<info>%d page(s)</info> are earning impressions while their stored verdict says otherwise — '
            .'the verdict is stale, not the page.', $r['stale_verdicts']));

        $this->explain(
            'Impressions, but the verdict is not PASS',
            'Inspected, and Google recorded something other than PASS — yet the page is earning impressions. '
                .'It was inspected before it got indexed, and the budget-capped inspector has not been back.',
            $r['causes']['impressions_but_verdict_says_no'],
        );

        $this->explain(
            'Impressions, but never inspected',
            'No verdict row at all. Both surfaces count it as indexed on the impressions alone; it is listed '
                .'so the inspection gap is visible.',
            $r['causes']['impressions_but_never_inspected'],
        );

        $this->newLine();
        if ($r['causes']['orphan_verdict_rows'] > 0) {
            $this->line(sprintf(
                '<comment>%d verdict row(s)</comment> belong to content that is no longer published — retired, unpublished or '
                .'replaced. They leave their verdict behind, so the board counted pages nobody can visit; the cards never could.',
                $r['causes']['orphan_verdict_rows'],
            ));
        }
        $this->line(sprintf('Context — %d published page(s) have never been inspected at all (the coverage gap); '
            .'%d have a PASS verdict but no impressions in the last %d days (indexed, just not earning); '
            .'%d earned impressions only OUTSIDE that window, so the cards no longer count them as in Google.',
            $r['causes']['never_inspected_total'],
            $r['causes']['pass_without_recent_impressions'],
            $r['window_days'],
            $r['causes']['impressions_outside_window'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{title: string, url: ?string, verdict: string}>  $rows
     */
    private function explain(string $heading, string $why, array $rows): void
    {
        $this->newLine();
        $this->line('<comment>'.$heading.'</comment> — '.count($rows).' shown');
        $this->line('  '.$why);
        if ($rows === []) {
            $this->line('  <info>None.</info>');

            return;
        }
        foreach ($rows as $row) {
            $this->line(sprintf('  · %-46s %s', mb_strimwidth($row['title'], 0, 46, '…'), $row['url'] ?? $row['verdict']));
        }
    }

    private function resolveSite(): ?Site
    {
        $id = $this->option('site');
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Site::query()->find($id) ?? Site::query()->where('brand_name', $id)->first();
    }
}
