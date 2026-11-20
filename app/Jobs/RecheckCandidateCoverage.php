<?php

namespace App\Jobs;

use App\ContentEngine\Coverage\CandidateCoverage;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\CoverageVerdict;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Re-stamps the coverage verdict on every open blog candidate of one site, on the worker. A verdict goes
 * stale two ways: a candidate stamped "new post" should read "refresh" the day a twin post publishes, and the
 * twin's Search Console standing moves weekly. The board's "Re-check coverage" queues this (one in flight per
 * site, state shown until the worker has finished — the first pass embeds every live title, a minute or two
 * no browser request should carry); the daily schedule runs the same check for every site after the GSC sync.
 */
class RecheckCandidateCoverage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    private const TTL = 86400;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    /** Stamp "queued" and dispatch (a second click while one runs is a no-op). */
    public static function start(Site $site): void
    {
        $current = self::status((string) $site->id);
        if (in_array($current['state'], ['queued', 'running'], true) && $current['at'] !== null && strtotime($current['at']) > time() - 900) {
            return;
        }
        Cache::put(self::key((string) $site->id), ['state' => 'queued', 'at' => now()->toIso8601String()], self::TTL);
        self::dispatch((string) $site->id);
    }

    /**
     * @return array{state: string, at: string|null, result: array<string, int>|null, error: string|null}
     *                                                                                                    state ∈ idle | queued | running | done | failed
     */
    public static function status(string $siteId): array
    {
        /** @var array<string, mixed>|null $raw */
        $raw = Cache::get(self::key($siteId));
        $raw = is_array($raw) ? $raw : [];

        return [
            'state' => (string) ($raw['state'] ?? 'idle'),
            'at' => isset($raw['at']) ? (string) $raw['at'] : null,
            'result' => is_array($raw['result'] ?? null) ? array_map('intval', $raw['result']) : null,
            'error' => isset($raw['error']) ? (string) $raw['error'] : null,
        ];
    }

    public function handle(CandidateCoverage $coverage): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            Cache::forget(self::key($this->siteId));

            return;
        }
        Cache::put(self::key($this->siteId), ['state' => 'running', 'at' => now()->toIso8601String()], self::TTL);

        try {
            $result = self::recheck($coverage, $site);
        } catch (Throwable $e) {
            Cache::put(self::key($this->siteId), ['state' => 'failed', 'at' => now()->toIso8601String(), 'error' => $e->getMessage()], self::TTL);

            return;
        }
        Cache::put(self::key($this->siteId), ['state' => 'done', 'at' => now()->toIso8601String(), 'result' => $result], self::TTL);
    }

    /**
     * Stamp every open candidate (candidate / scored / in_review) of the site. Shared with the command's
     * `--all --execute` so the schedule, the button and the console do the same thing.
     *
     * @return array<string, int> verdict → count, plus `checked`
     */
    public static function recheck(CandidateCoverage $coverage, Site $site): array
    {
        $counts = array_fill_keys(array_map(fn (CoverageVerdict $v): string => $v->value, CoverageVerdict::cases()), 0);
        $checked = 0;
        Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::Scored->value, ContentStatus::InReview->value])
            ->chunkById(50, function ($rows) use ($coverage, $site, &$counts, &$checked): void {
                foreach ($rows as $row) {
                    $counts[$coverage->stamp($site, $row)->verdict->value]++;
                    $checked++;
                }
            });

        return ['checked' => $checked, ...$counts];
    }

    private static function key(string $siteId): string
    {
        return 'coverage.recheck.'.$siteId;
    }
}
