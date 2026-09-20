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

        $this->line('The board counts URL-Inspection PASS verdicts over the pages it has INSPECTED.');
        $this->line('A card calls a page indexed on PASS <comment>or</comment> Search impressions, over EVERY published page —');
        $this->line('impressions are proof a page is in the index; their absence proves nothing, so it is an OR.');
        $this->newLine();

        $this->line(sprintf('<info>%d page(s)</info> the cards call indexed and the board does not.', $r['disagreements']));

        $this->explain(
            'Impressions, but the verdict is not PASS',
            'Inspected, and Google says something other than PASS — yet the page is earning impressions. '
                .'Usually a stale verdict: it was inspected before it got indexed.',
            $r['causes']['impressions_but_verdict_says_no'],
        );

        $this->explain(
            'Impressions, but never inspected',
            'Invisible to the board entirely — it has no verdict row, so it is not in the denominator. '
                .'The cards see it, because impressions do not need an inspection.',
            $r['causes']['impressions_but_never_inspected'],
        );

        $this->newLine();
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
