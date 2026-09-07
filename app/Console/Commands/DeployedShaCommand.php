<?php

namespace App\Console\Commands;

use App\Operator\DeployLag;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Report (read-only) the DEPLOYED git revision of this checkout, how far behind `origin/main` it is, and
 * the age-driven severity (fresh / late / stale) — so "is this change live yet?" is answerable BEFORE
 * running a command against an environment, not discovered after stale code confuses a diagnosis. Severity
 * and the behind-count come from the shared {@see DeployLag} (same source the lobby's platform notice uses).
 *
 * Offline by default (compares to the last-fetched `origin/main`, flagged possibly stale); `--fetch`
 * refreshes for a current count. `--behind=N` makes the command exit non-zero when at least N commits
 * behind — a signal for external monitoring (nothing consumes it in-app yet).
 */
class DeployedShaCommand extends Command
{
    protected $signature = 'launchpad:deployed-sha
        {--fetch : git fetch origin/main first for a CURRENT count (network side effect)}
        {--behind= : exit non-zero when at least N commits behind origin/main (for external monitoring)}';

    protected $description = 'Report (read-only) the deployed git SHA, how far behind origin/main, and the fresh/late/stale severity.';

    public function handle(DeployLag $lag): int
    {
        $snap = $lag->compute((bool) $this->option('fetch'));
        if ($snap['deployed_sha'] === null) {
            $this->error('Not a git checkout here (no .git) — this deploy cannot self-report its SHA. Check the deploy host / pipeline directly.');

            return self::FAILURE;
        }

        $this->info('Deployed revision (this checkout):');
        $this->line("  <options=bold>{$snap['deployed_short']}</>  ".($snap['subject'] ?? '(unknown)'));
        $this->line('  committed '.($snap['committed_at'] ?? '(unknown)'));
        $this->line("  full {$snap['deployed_sha']}");
        $this->newLine();

        $behind = $snap['behind'];
        if ($behind === null) {
            $this->warn("Could not compare to origin/main (no such ref locally). Compare {$snap['deployed_short']} against GitHub main by hand.");

            return self::SUCCESS;
        }

        $freshness = $this->option('fetch') ? '' : ' (as last fetched — pass --fetch for the current count)';
        $age = $this->oldestAge($snap);
        match ($snap['severity']) {
            'stale' => $this->error("STALE — {$behind} commit(s) behind origin/main; oldest undeployed change {$age} old — the deploy pipeline looks stuck{$freshness}."),
            'late' => $this->warn("LATE — {$behind} commit(s) behind origin/main; oldest undeployed change {$age} (a deploy may be in flight){$freshness}."),
            default => $this->info("Up to date with origin/main{$freshness}."),
        };

        if ($behind > 0) {
            $this->newLine();
            $this->line('Undeployed (on origin/main, not in this checkout):');
            foreach ($lag->undeployedCommits() as $line) {
                $this->line("  {$line}");
            }
        }

        // --behind=N: a non-zero exit for external monitoring (count-based, independent of the age severity).
        $threshold = $this->option('behind');
        if ($threshold !== null && $behind >= max(1, (int) $threshold)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Human age of the oldest undeployed commit, e.g. "7h", or "age unknown". */
    private function oldestAge(array $snap): string
    {
        $at = $snap['oldest_undeployed_at'] ?? null;

        return is_string($at) ? ((int) Carbon::parse($at)->diffInHours(now())).'h' : 'age unknown';
    }
}
