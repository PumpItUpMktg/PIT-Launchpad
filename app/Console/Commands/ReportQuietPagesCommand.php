<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\Coverage\QuietPages;
use Illuminate\Console\Command;

/**
 * Pages Google holds but never shows — indexed, earning nothing in Search, and WHY.
 *
 *   launchpad:report-quiet-pages --site=... [--examples=25]
 *
 * Being indexed is not being found. This splits the silent set into the four things it actually is —
 * too young to judge, no search demand to win, lapsed from something it used to hold, or orphaned with
 * no internal links — so the headline number is not read as one problem when most of it is not a problem
 * at all. The last section is the intersection worth acting on.
 *
 * Read-only and HTTP-free.
 */
class ReportQuietPagesCommand extends Command
{
    protected $signature = 'launchpad:report-quiet-pages
        {--site= : Site id or brand name}
        {--examples=25 : How many actionable pages to list}';

    protected $description = 'Profile the published pages that are indexed but earning no Search impressions.';

    public function handle(QuietPages $quiet): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('No site found — pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $r = $quiet->for($site, max(1, (int) $this->option('examples')));

        $this->info($site->brand_name.' — indexed pages earning nothing in Search');
        $this->newLine();

        if ($r['total'] === 0) {
            $this->line('<info>Every indexed page earned impressions in the window.</info>');

            return self::SUCCESS;
        }

        $share = $r['indexed_total'] > 0 ? round($r['total'] / $r['indexed_total'] * 100) : 0;
        $this->line(sprintf('<info>%d of %d indexed page(s)</info> (%d%%) earned no impressions in the last 28 days.',
            $r['total'], $r['indexed_total'], $share));
        $this->newLine();

        $this->table(['Why it may be silent', 'Pages', 'Reading'], [
            ['Never earned an impression', $r['never_earned'], 'It has not ranked for anything yet'],
            ['Earned before, silent now', $r['lapsed'], 'Lost something it used to hold — the only one with a before'],
            ['Published under 30 days ago', $r['too_young'], 'Has not had a fair chance; not a failure'],
            ['Target term has no real demand', $r['by_demand']['thin'], 'Zero impressions is the CORRECT outcome here'],
            ['No target keyword at all', $r['by_demand']['no_target'], 'Nothing was aimed at; nothing to miss'],
            ['Nothing links to it internally', $r['no_inbound_links'], 'Reachable by sitemap, unsupported by the site'],
        ]);

        $this->line('These overlap — a page can be young AND orphaned. They are four readings of the same set, not a split of it.');
        $this->newLine();

        $byType = [];
        foreach ($r['by_type'] as $type => $count) {
            $byType[] = [$type, $count];
        }
        $this->table(['Lane', 'Silent pages'], $byType);

        $this->newLine();
        $this->line(sprintf('<comment>Worth acting on — %d shown</comment>: old enough to have had a chance, targeting a term with '
            .'real volume, still silent. Least-linked first, then most volume going to waste.', count($r['actionable'])));
        if ($r['actionable'] === []) {
            $this->line('  <info>None.</info> Everything silent is young, aimed at a term with no demand, or both.');

            return self::SUCCESS;
        }
        foreach ($r['actionable'] as $row) {
            $this->line(sprintf('  · %-42s %-8s %4s vol  %d inbound  %s%s',
                mb_strimwidth($row['title'], 0, 42, '…'),
                $row['type'],
                $row['volume'] ?? '—',
                $row['inbound'],
                $row['days'] !== null ? $row['days'].'d old' : 'undated',
                $row['lapsed'] ? '  (was earning)' : '',
            ));
        }

        return self::SUCCESS;
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
