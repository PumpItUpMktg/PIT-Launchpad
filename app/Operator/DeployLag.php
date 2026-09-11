<?php

namespace App\Operator;

use App\Console\Commands\CheckDeployLagCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * How far the DEPLOYED checkout is behind `origin/main`, and how urgently that reads — the single source
 * behind both `launchpad:deployed-sha` (the CLI answer) and the lobby's platform-level deploy-lag notice.
 *
 * Severity is AGE-driven, not count-driven — the freshness-stamp model. Behind by one commit for two
 * minutes is a deploy in flight; behind for six hours is a stuck pipeline (the shape that let the feeds die
 * for sixteen days and this session's own deploy sit unnoticed). So:
 *   - FRESH  — up to date (0 behind);
 *   - LATE   — behind, but the OLDEST undeployed commit is younger than the stale cutoff (plausibly a deploy
 *              mid-flight);
 *   - STALE  — behind, and the oldest undeployed commit is older than the cutoff (config
 *              `launchpad.deploy_lag_stale_hours`, default 6h) → the pipeline is stuck.
 *
 * {@see compute()} runs git (for the CLI and the hourly {@see CheckDeployLagCommand});
 * the lobby never shells out per request — it reads the last {@see store()}d snapshot via {@see notice()},
 * which RE-derives severity from the stored oldest-commit timestamp against now(), so LATE escalates to
 * STALE exactly at the cutoff without waiting for the next hourly check.
 */
final class DeployLag
{
    private const KEY = 'deploy_lag';

    /** The age-driven severity for a behind-count + the oldest undeployed commit's timestamp. Pure. */
    public function severityFor(int $behind, ?Carbon $oldestUndeployedAt): string
    {
        if ($behind <= 0) {
            return 'fresh';
        }
        if ($oldestUndeployedAt === null) {
            return 'late'; // behind, but age unknown — don't escalate without evidence
        }

        return $oldestUndeployedAt->diffInHours(now()) >= $this->staleHours() ? 'stale' : 'late';
    }

    /**
     * Live snapshot from git in the app root. `$fetch` refreshes `origin/main` first (network) for a current
     * count; without it the comparison is against the last-fetched ref. Null fields where git can't answer.
     *
     * @return array{deployed_sha: ?string, deployed_short: ?string, subject: ?string, committed_at: ?string,
     *   behind: ?int, oldest_undeployed_at: ?string, severity: string, checked_at: string}
     */
    public function compute(bool $fetch = false): array
    {
        $sha = $this->deployedSha();
        if ($fetch) {
            $this->git(['fetch', '--quiet', 'origin', 'main']);
        }

        $behindRaw = $this->git(['rev-list', '--count', 'HEAD..origin/main']);
        $behind = $behindRaw !== null ? (int) $behindRaw : null;

        // The OLDEST undeployed commit's committer date (ISO) — the one whose age decides stale.
        $oldest = null;
        if ($behind !== null && $behind > 0) {
            $log = $this->git(['log', '--reverse', '--format=%cI', 'HEAD..origin/main']);
            $oldest = $log !== null && $log !== '' ? explode("\n", $log)[0] : null;
        }

        return [
            'deployed_sha' => $sha,
            // Short form derived from the resolved SHA so it never disagrees with deployed_sha (git --short
            // would report the working-tree HEAD, which a build-time APP_REVISION need not match).
            'deployed_short' => $sha !== null ? substr($sha, 0, 12) : null,
            'subject' => $this->git(['show', '-s', '--format=%s', 'HEAD']),
            'committed_at' => $this->git(['show', '-s', '--format=%cI', 'HEAD']),
            'behind' => $behind,
            'oldest_undeployed_at' => $oldest,
            'severity' => $this->severityFor($behind ?? 0, $oldest !== null ? Carbon::parse($oldest) : null),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** Persist a snapshot (from {@see compute()}) for the lobby to read without shelling out. */
    public function store(array $snapshot): void
    {
        Cache::forever(self::KEY, $snapshot);
    }

    /** @return array<string, mixed>|null the last stored snapshot, or null if never checked. */
    public function stored(): ?array
    {
        $s = Cache::get(self::KEY);

        return is_array($s) ? $s : null;
    }

    /**
     * The lobby's platform-level notice — non-null ONLY when the deploy is STALE (materially behind). One
     * notice for the whole platform, not per tenant. Severity is re-derived live from the stored oldest
     * timestamp, so it flips to stale at the cutoff between hourly checks.
     *
     * @return array{behind: int, oldest_hours: int, deployed_short: ?string, checked_at: ?string}|null
     */
    public function notice(): ?array
    {
        $s = $this->stored();
        if ($s === null) {
            return null;
        }

        $behind = (int) ($s['behind'] ?? 0);
        $oldest = isset($s['oldest_undeployed_at']) && is_string($s['oldest_undeployed_at']) ? Carbon::parse($s['oldest_undeployed_at']) : null;
        if ($this->severityFor($behind, $oldest) !== 'stale') {
            return null;
        }

        return [
            'behind' => $behind,
            'oldest_hours' => $oldest !== null ? (int) $oldest->diffInHours(now()) : 0,
            'deployed_short' => is_string($s['deployed_short'] ?? null) ? $s['deployed_short'] : null,
            'checked_at' => is_string($s['checked_at'] ?? null) ? $s['checked_at'] : null,
        ];
    }

    /** @return list<string> the undeployed commits (HEAD..origin/main), oneline — CLI display only. */
    public function undeployedCommits(): array
    {
        $log = $this->git(['log', '--oneline', '--no-decorate', 'HEAD..origin/main']);

        return $log !== null && $log !== '' ? explode("\n", $log) : [];
    }

    private function staleHours(): int
    {
        return max(1, (int) config('launchpad.deploy_lag_stale_hours', 6));
    }

    /**
     * The deployed build's git SHA. A production artifact has no `.git`, so prefer a build-time source — the
     * `APP_REVISION` env (via `config('app.revision')`) the deploy pipeline stamps, then a committed
     * `REVISION` file at the app root — and fall back to `git rev-parse HEAD` for local development. Null
     * only when none resolve.
     */
    private function deployedSha(): ?string
    {
        $configured = config('app.revision');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $file = base_path('REVISION');
        if (is_file($file)) {
            $contents = trim((string) @file_get_contents($file));
            if ($contents !== '') {
                return $contents;
            }
        }

        return $this->git(['rev-parse', 'HEAD']);
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
