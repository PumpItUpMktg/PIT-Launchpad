<?php

namespace App\Jobs;

use App\Models\Site;
use App\Social\ChannelSync;
use App\Social\ProfilePicker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pulls one site's channels from the posting provider on the worker, off the web request. A sync reads
 * every GBP listing's own record (address, place id) — one provider call per listing — so a tenant with
 * three profiles and two dozen listings runs well past what a browser request allows. "Sync now" queues
 * this and the Shares page shows the state ({@see status()}) until the worker has finished.
 */
class SyncSocialChannels implements ShouldBeUnique, ShouldQueue
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

    /** Stamp "queued" and dispatch (one in flight per site; a second click while it runs is a no-op). */
    public static function start(Site $site): void
    {
        $current = self::status((string) $site->id);
        if (in_array($current['state'], ['queued', 'running'], true) && $current['at'] !== null && strtotime($current['at']) > time() - 900) {
            return;
        }
        Cache::put(self::key((string) $site->id), ['state' => 'queued', 'at' => now()->toIso8601String()], self::TTL);
        ProfilePicker::forget();
        self::dispatch((string) $site->id);
    }

    /**
     * @return array{state: string, at: string|null, result: array<string, mixed>|null, error: string|null}
     *                                                                                                      state ∈ idle | queued | running | done | failed
     */
    public static function status(string $siteId): array
    {
        /** @var array<string, mixed>|null $raw */
        $raw = Cache::get(self::key($siteId));
        $raw = is_array($raw) ? $raw : [];

        return [
            'state' => (string) ($raw['state'] ?? 'idle'),
            'at' => isset($raw['at']) ? (string) $raw['at'] : null,
            'result' => is_array($raw['result'] ?? null) ? $raw['result'] : null,
            'error' => isset($raw['error']) ? (string) $raw['error'] : null,
        ];
    }

    public function handle(ChannelSync $sync): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            Cache::forget(self::key($this->siteId));

            return;
        }
        Cache::put(self::key($this->siteId), ['state' => 'running', 'at' => now()->toIso8601String()], self::TTL);
        try {
            $result = $sync->sync($site);
        } catch (Throwable $e) {
            Cache::put(self::key($this->siteId), ['state' => 'failed', 'at' => now()->toIso8601String(), 'error' => $e->getMessage()], self::TTL);

            return;
        }
        Cache::put(self::key($this->siteId), ['state' => 'done', 'at' => now()->toIso8601String(), 'result' => $result], self::TTL);
    }

    private static function key(string $siteId): string
    {
        return 'social.channel_sync.'.$siteId;
    }
}
