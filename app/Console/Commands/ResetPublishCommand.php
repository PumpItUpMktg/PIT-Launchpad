<?php

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clear a tenant's STUCK / FAILED publishes — the recovery when a wave of publish jobs failed (e.g. a
 * revoked WP app password 401'd every push) and the pages are parked in `publish_failed` / `render_failed`
 * (or mid-flight `rendering` / `publishing` behind a dead worker). Two outcomes:
 *
 * - **default → `approved`**: the clean, re-publishable state, so after fixing the cause (re-verify +
 *   rotate the connection, restart the worker) a `launchpad:drain-publish` or the worker re-pushes them.
 * - **`--reject` → `rejected`**: pull them OUT of the publish lane entirely (they don't publish at all);
 *   a `--reason` is stamped and they can be regenerated later if wanted.
 *
 * `--flush-failed` also forgets the tenant's dead `failed_jobs` rows (matched by the reset content ids in
 * the serialized payload) so the "N failed" banner clears. `--dry-run` lists without changing anything.
 * Only touches the terminal/in-flight publish statuses — never a `published` (live) page.
 */
class ResetPublishCommand extends Command
{
    protected $signature = 'launchpad:reset-publish {site : Site id or brand name}
        {--reject : Reject the stuck items (pull them out of the pipeline) instead of resetting to approved}
        {--reason= : Reject reason (with --reject; default a generic recovery note)}
        {--flush-failed : Also delete this tenant\'s dead failed_jobs rows}
        {--dry-run : List what would change without touching anything}';

    protected $description = 'Clear a tenant\'s stuck/failed publishes — reset to approved (default) or --reject them out of the pipeline; optionally clear their dead failed jobs.';

    /** The statuses a stuck/failed publish sits in — everything past approve, short of live. */
    private const STUCK = [
        ContentStatus::Rendering->value,
        ContentStatus::Publishing->value,
        ContentStatus::RenderFailed->value,
        ContentStatus::PublishFailed->value,
    ];

    public function handle(): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))->orWhere('brand_name', $this->argument('site'))->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        $stuck = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('status', self::STUCK)
            ->orderBy('updated_at')
            ->get();

        if ($stuck->isEmpty()) {
            $this->info("{$site->brand_name}: nothing stuck — no rendering/publishing/failed pages to reset.");

            return self::SUCCESS;
        }

        $byStatus = $stuck->countBy(fn (Content $c): string => $c->status->value);
        $this->line("<info>{$site->brand_name}</info> — {$stuck->count()} stuck item(s): "
            .$byStatus->map(fn (int $n, string $s): string => "{$n} {$s}")->implode(', ').'.');

        $reject = (bool) $this->option('reject');
        $target = $reject ? ContentStatus::Rejected : ContentStatus::Approved;

        if ($this->option('dry-run')) {
            foreach ($stuck as $c) {
                $this->line("  • {$c->title}  ·  {$c->status->value}");
            }
            $this->comment('Dry run — nothing changed. Re-run without --dry-run to '
                .($reject ? 'reject these (pull them out of the pipeline).' : 'reset these to approved.'));

            return self::SUCCESS;
        }

        $ids = $stuck->pluck('id')->all();
        $update = ['status' => $target->value, 'last_publish_error' => null];
        if ($reject) {
            $reason = trim((string) $this->option('reason'));
            $update['reject_reason'] = $reason !== '' ? $reason : 'Bulk-rejected from launchpad:reset-publish (recovery).';
        }

        Content::withoutGlobalScope(SiteScope::class)->whereIn('id', $ids)->update($update);

        $this->line($reject
            ? "Rejected {$stuck->count()} item(s) — pulled out of the publish pipeline (regenerate them later if wanted)."
            : "Reset {$stuck->count()} item(s) to <info>approved</info> — re-push them with launchpad:drain-publish (or a running worker) once the cause is fixed.");

        if ($this->option('flush-failed')) {
            $flushed = $this->flushFailedFor($ids);
            $this->line("Cleared {$flushed} dead failed-job(s) for this tenant.");
        }

        return self::SUCCESS;
    }

    /**
     * Delete the failed_jobs rows whose serialized payload references one of the reset content ids —
     * ULIDs are unique, so a substring match on the payload is a safe per-tenant scope.
     *
     * @param  list<string>  $contentIds
     */
    private function flushFailedFor(array $contentIds): int
    {
        if ($contentIds === []) {
            return 0;
        }

        $query = DB::table('failed_jobs');
        $query->where(function ($q) use ($contentIds): void {
            foreach ($contentIds as $id) {
                $q->orWhere('payload', 'like', '%'.$id.'%');
            }
        });

        return $query->delete();
    }
}
