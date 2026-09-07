<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Report (read-only) the DEPLOYED git revision of this checkout and how far behind `origin/main` it is —
 * so "is this change live yet?" is answerable BEFORE running a command against an environment, not
 * discovered after stale code confuses a diagnosis (a pre-deploy `report-market-geo` sent an operator in a
 * circle this way). Same principle as a freshness stamp: a surface that can't say how current it is will
 * eventually mislead confidently.
 *
 * Offline by default: reads local HEAD and compares to the LAST-FETCHED `origin/main` ref (flagged as
 * possibly stale). `--fetch` refreshes `origin/main` first for a current count (a network side effect,
 * opt-in). When behind, it lists the exact undeployed commits — the answer to "which merges aren't live".
 */
class DeployedShaCommand extends Command
{
    protected $signature = 'launchpad:deployed-sha {--fetch : git fetch origin/main first for a CURRENT behind-count (network side effect)}';

    protected $description = 'Report (read-only) the deployed git SHA + how far behind origin/main it is (and which commits are undeployed).';

    public function handle(): int
    {
        $sha = $this->git(['rev-parse', 'HEAD']);
        if ($sha === null) {
            $this->error('Not a git checkout here (no .git) — this deploy cannot self-report its SHA. Check the deploy host / pipeline directly.');

            return self::FAILURE;
        }

        $short = $this->git(['rev-parse', '--short', 'HEAD']) ?? substr($sha, 0, 12);
        $subject = $this->git(['show', '-s', '--format=%s', 'HEAD']) ?? '(unknown)';
        $date = $this->git(['show', '-s', '--format=%ci', 'HEAD']) ?? '(unknown)';

        $this->info('Deployed revision (this checkout):');
        $this->line("  <options=bold>{$short}</>  {$subject}");
        $this->line("  committed {$date}");
        $this->line("  full {$sha}");

        $fetched = false;
        if ($this->option('fetch')) {
            $fetched = $this->git(['fetch', '--quiet', 'origin', 'main']) !== null;
        }
        $freshness = $fetched ? '' : ' (as last fetched — pass --fetch for the current count)';

        $behind = $this->git(['rev-list', '--count', 'HEAD..origin/main']);
        $this->newLine();
        if ($behind === null) {
            $this->warn("Could not compare to origin/main (no such ref locally). Compare {$short} against GitHub main by hand.");

            return self::SUCCESS;
        }
        if ($behind === '0') {
            $this->info("Up to date with origin/main{$freshness}.");

            return self::SUCCESS;
        }

        $ahead = $this->git(['rev-list', '--count', 'origin/main..HEAD']);
        $aheadNote = ($ahead !== null && $ahead !== '0') ? " (+{$ahead} ahead)" : '';
        $this->warn("{$behind} commit(s) BEHIND origin/main{$aheadNote}{$freshness}.");

        $log = $this->git(['log', '--oneline', '--no-decorate', 'HEAD..origin/main']);
        if ($log !== null && $log !== '') {
            $this->newLine();
            $this->line('Undeployed (on origin/main, not in this checkout):');
            foreach (explode("\n", $log) as $line) {
                $this->line("  {$line}");
            }
        }

        return self::SUCCESS;
    }

    /** Run git in the app root; trimmed stdout, or null on failure (non-zero exit, or git/.git missing). */
    private function git(array $args): ?string
    {
        try {
            $result = Process::path(base_path())->run(array_merge(['git'], $args));
        } catch (\Throwable) {
            return null;
        }

        return $result->successful() ? trim($result->output()) : null;
    }
}
