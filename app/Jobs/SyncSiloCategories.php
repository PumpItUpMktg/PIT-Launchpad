<?php

namespace App\Jobs;

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\LaunchOrchestrator;
use App\Publishing\PublishSiloService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Project a site's locked taxonomy into WordPress categories — the §4 silo tree → /silo. Dispatched
 * at Finalize Plan (the same moment page materialize runs, when the taxonomy is locked), on the queue
 * so Finalize itself stays network-free like permalink assignment. Idempotent by ULID, so the go-live
 * launch ({@see LaunchOrchestrator}) re-pushes harmlessly as a backstop.
 *
 * Why here and not at first-post: the news engine maps a post to its silo's category, and §2 publish
 * flows from Active (before go-live) — so the categories must exist before any post publishes, not be
 * created lazily when slugs may have drifted.
 *
 * No-op until a WP connection is wired (a site can finalize before its connection lands); the launch
 * backstop covers that case. Existence-checked, NOT the §9 verified/clean gate — publishing to the
 * blank instance is allowed from Active.
 */
class SyncSiloCategories implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $siteId) {}

    /** Dispatch helper (kept symmetric with the other build jobs). */
    public static function enqueue(Site $site): void
    {
        self::dispatch($site->id);
    }

    public function handle(PublishSiloService $service): void
    {
        $site = Site::withoutGlobalScope(SiteScope::class)->find($this->siteId);
        if ($site === null) {
            return;
        }

        $hasConnection = Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword->value)
            ->exists();

        if (! $hasConnection) {
            return; // no WP yet — go-live launch is the backstop
        }

        $service->publishSite($site);
    }
}
