<?php

namespace App\Console\Commands;

use App\ContentEngine\Questions\QuestionCluster;
use App\ContentEngine\Questions\QuestionIntake;
use App\ContentEngine\Questions\QuestionMiner;
use App\ContentEngine\Questions\QuestionMiningReport;
use App\ContentEngine\Questions\QuestionVerdict;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Question mining — what a site's searchers are asking, from its own Search Console store, and what the
 * blog should do about each. Report-first: the default run prints the counts, the top topics, where the
 * site already ranks for each and the planned action, and changes nothing; --execute files the new
 * "questions" candidates and marks the posts to refresh. Monthly on the schedule with --all --execute.
 */
class MineQuestionsCommand extends Command
{
    protected $signature = 'launchpad:mine-questions
        {site? : Site id, brand name, or domain (partial ok)}
        {--all : Every site with a Search Console property picked}
        {--window= : Trailing window in days (default config launchpad.questions.window_days)}
        {--top= : Topics to act on per run (default config launchpad.questions.top)}
        {--execute : File the new candidates and mark the posts to refresh (default: report only)}
        {--dropped : Also list every query the bot guard dropped, with the rule}';

    protected $description = 'Mine question-shaped searches from the Search Console store (no vendor call): question shape → bot guard → clustered topics → coverage verdict; --execute files candidates / marks refreshes.';

    public function handle(QuestionMiner $miner, QuestionIntake $intake): int
    {
        $sites = $this->sites();
        if ($sites === null) {
            return self::FAILURE;
        }
        if ($sites->isEmpty()) {
            $this->warn('No site to mine — pass a site, or --all for every site with a Search Console property picked.');

            return self::SUCCESS;
        }

        $window = $this->option('window') !== null ? max(1, (int) $this->option('window')) : null;
        $top = $this->option('top') !== null ? max(1, (int) $this->option('top')) : (int) config('launchpad.questions.top', 15);

        $execute = (bool) $this->option('execute');

        foreach ($sites as $site) {
            $report = $miner->mine($site, $window);
            $plan = $intake->plan($site, $report, $top);
            $this->printReport($site, $report, $plan, $top);
            if (! $execute) {
                continue;
            }
            $done = $intake->apply($site, $report, $plan);
            $this->line(sprintf('  <info>Applied</info>: filed %d candidate(s) · marked %d question(s) on %d post(s) for refresh · re-stamped %d standing candidate(s).', $done['filed'], $done['marked'], $done['posts_marked'], $done['refreshed']));
        }
        if (! $execute) {
            $this->comment('Report only — nothing written. Re-run with --execute to file the candidates and mark the refreshes.');
        }

        return self::SUCCESS;
    }

    /** @param  list<array{cluster: QuestionCluster, verdict: QuestionVerdict, silo: Silo|null, action: string, existing: Content|null, routed_via: string|null}>  $plan */
    private function printReport(Site $site, QuestionMiningReport $report, array $plan, int $top): void
    {
        $this->newLine();
        $this->line(sprintf('<info>%s</info> — Search Console questions, %s → %s (%d days)', $site->brand_name, $report->from, $report->to, $report->windowDays));
        $byRule = $report->botDroppedByRule();
        $this->line(sprintf(
            '  queries read %d · question-shaped %d · dropped as machine traffic %d%s · topics %d',
            $report->queriesRead,
            $report->questionShaped,
            count($report->botDropped),
            $byRule !== [] ? ' ('.implode(', ', array_map(fn (string $r, int $n): string => "{$r} {$n}", array_keys($byRule), $byRule)).')' : '',
            count($report->clusters),
        ));

        if ($report->clusters === []) {
            $this->comment('  No question-shaped searches in the window — is the Search Console sync filling this site\'s store?');

            return;
        }

        $rows = [];
        $actions = [];
        foreach ($plan as $i => $row) {
            $cluster = $row['cluster'];
            $verdict = $row['verdict'];
            $actions[$row['action']] = ($actions[$row['action']] ?? 0) + 1;
            $rows[] = [
                $i + 1,
                $this->truncate($cluster->query(), 48),
                count($cluster->variants) > 1 ? '+'.(count($cluster->variants) - 1) : '',
                $cluster->impressions(),
                $cluster->clicks(),
                $verdict->position !== null ? number_format($verdict->position, 1) : '—',
                $this->path($verdict->rankingUrl),
                $this->actionLabel($row),
            ];
        }
        $this->table(['#', 'Question', 'Also', 'Impr.', 'Clicks', 'Pos.', 'Ranking URL', 'Action'], $rows);
        ksort($actions);
        $this->line('  '.implode(' · ', array_map(fn (string $a, int $n): string => "{$a} {$n}", array_keys($actions), $actions)));
        $beyond = count($report->clusters) - count($plan);
        if ($beyond > 0) {
            $this->line(sprintf('  … %d more topic(s) beyond this run\'s cap of %d — they wait for next month.', $beyond, $top));
        }

        if ((bool) $this->option('dropped') && $report->botDropped !== []) {
            $this->newLine();
            $this->line('  Dropped as machine traffic:');
            $this->table(['Query', 'Rule', 'Impr.', 'Clicks', 'Pos.'], array_map(fn (array $d): array => [
                $this->truncate($d['query'], 58), $d['reason'], $d['impressions'], $d['clicks'],
                $d['position'] !== null ? number_format($d['position'], 1) : '—',
            ], $report->botDropped));
        }
    }

    /** @param  array{cluster: QuestionCluster, verdict: QuestionVerdict, silo: Silo|null, action: string, existing: Content|null, routed_via: string|null}  $row */
    private function actionLabel(array $row): string
    {
        $who = $row['existing'] !== null ? ' → '.$this->truncate((string) $row['existing']->title, 34) : '';

        return match ($row['action']) {
            QuestionIntake::ACTION_FILE => 'file ['.$this->truncate((string) $row['silo']?->name, 22).']'.($row['routed_via'] === 'ranking_page' ? ' via ranking page' : ''),
            QuestionIntake::ACTION_ORPHANED => 'orphaned → '.$this->truncate((string) $row['existing']?->title, 28).' ('.($row['existing']?->status->value ?? '?').($row['existing']?->deleted_at !== null ? ', deleted' : '').')',
            QuestionIntake::ACTION_MARK => 'refresh'.$who,
            QuestionIntake::ACTION_ALREADY => 'on board'.$who,
            QuestionIntake::ACTION_DISMISSED => 'dismissed before',
            QuestionIntake::ACTION_UNROUTED => 'unrouted (no silo claims it)',
            default => 'covered'.$who,
        };
    }

    private function path(?string $url): string
    {
        if ($url === null) {
            return '—';
        }
        $path = parse_url($url, PHP_URL_PATH);

        return $this->truncate(is_string($path) && $path !== '' ? $path : $url, 44);
    }

    private function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }

    /** @return Collection<int, Site>|null null on an unresolvable site argument */
    private function sites(): ?Collection
    {
        if ((bool) $this->option('all')) {
            return Site::withoutGlobalScopes()->whereNotNull('gsc_property')->where('gsc_property', '!=', '')->orderBy('brand_name')->get();
        }
        $needle = trim((string) $this->argument('site'));
        if ($needle === '') {
            return collect();
        }
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return null;
        }

        return $matches->values();
    }
}
