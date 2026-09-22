<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Redirects\RevivalOutcome;
use Illuminate\Console\Command;

/**
 * Did the revived posts hold what the pages they replaced were earning?
 *
 *   launchpad:report-revivals --site=... [--days=28]
 *
 * Before is the family's old URLs, after is the new post, the same number of days each side. Residual is
 * what the old URLs still earn after publish and should be nothing. Query coverage is the direct test of
 * the brief. Verdicts are comparisons, never attributions — the ratio is printed beside the word so
 * nobody has to trust the word.
 *
 * Read-only and HTTP-free.
 */
class ReportRevivalsCommand extends Command
{
    protected $signature = 'launchpad:report-revivals
        {--site= : Site id or brand name}
        {--days=28 : Days to compare on each side of publish}';

    protected $description = 'Compare each revived post against the legacy family it replaced — before, after, residual, and query coverage.';

    public function handle(RevivalOutcome $outcome): int
    {
        $arg = trim((string) $this->option('site'));
        $site = $arg === '' ? null : Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error('--site is required (id or brand name).');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $rows = $outcome->for($site, $days);

        $this->info("{$site->brand_name} — revived posts against the families they replaced");
        if ($rows === []) {
            $this->line('  No revived post has been published yet. Nothing to compare until one goes live.');

            return self::SUCCESS;
        }

        $tally = [];
        foreach ($rows as $r) {
            $tally[$r['verdict']] = ($tally[$r['verdict']] ?? 0) + 1;

            $this->newLine();
            $this->line(sprintf('<comment>%s</comment>  %s', $r['title'], $this->label($r['verdict'], $r['ratio'])));
            $this->line(sprintf('    published %s · %d day(s) ago · comparing %d day(s) each side',
                $r['published_on'] ?? '—', $r['elapsed_days'], $r['days_compared']));

            if ($r['verdict'] === 'too_early') {
                $this->line('    Google has barely seen it. Come back after a week.');

                continue;
            }

            $this->line(sprintf('    before  %8s impressions  %6s clicks  position %s   (the family\'s old URLs)',
                number_format($r['before']['impressions']), number_format($r['before']['clicks']), $this->pos($r['before']['position'])));
            $this->line(sprintf('    after   %8s impressions  %6s clicks  position %s   (the new post)',
                number_format($r['after']['impressions']), number_format($r['after']['clicks']), $this->pos($r['after']['position'])));

            if ($r['residual'] > 0) {
                $this->line(sprintf('    <comment>residual</comment>  the old URLs still earned %s impression(s) after publish — the 301 may not be '
                    .'landing, or Google has not re-crawled; the "after" number is being read early.', number_format($r['residual'])));
            }

            $q = $r['queries'];
            if ($q['brief'] > 0) {
                $this->line(sprintf('    queries  ranks for %d of the %d it was briefed on%s',
                    $q['ranking'], $q['brief'], $q['lost'] === [] ? '.' : ' — not yet: '.implode(', ', array_map(fn (string $s): string => '“'.$s.'”', array_slice($q['lost'], 0, 6))).(count($q['lost']) > 6 ? ', …' : '')));
            }
        }

        $this->newLine();
        arsort($tally);
        $this->line(implode(' · ', array_map(fn (string $v, int $n): string => "{$n} {$v}", array_keys($tally), $tally)));
        $this->line('A verdict is a comparison, not a cause — a rewrite published the week a competitor folded reads the same as a better one.');

        return self::SUCCESS;
    }

    private function label(string $verdict, ?float $ratio): string
    {
        $r = $ratio === null ? '' : sprintf(' (%.2fx)', $ratio);

        return match ($verdict) {
            'gained' => '<info>gained</info>'.$r,
            'held' => '<info>held</info>'.$r,
            'softened' => '<comment>softened</comment>'.$r,
            'lost' => '<error>lost</error>'.$r,
            'too_early' => 'too early to say',
            default => 'no baseline — the old URLs earned nothing in the window before publish',
        };
    }

    private function pos(?float $p): string
    {
        return $p === null ? '—' : number_format($p, 1);
    }
}
