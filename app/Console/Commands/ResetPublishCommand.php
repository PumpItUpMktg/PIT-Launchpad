<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clear a tenant's STUCK / FAILED publishes — the recovery when a wave of publish jobs failed (e.g. a
 * revoked WP app password 401'd every push) or piled up behind a dead worker. It catches two shapes of
 * "stuck":
 *
 * - a stuck CONTENT STATUS — `render_failed` / `publish_failed` (a publish that ran and failed) or
 *   mid-flight `rendering` / `publishing`;
 * - an `approved` page whose publish JOB is stuck in the queue — a pending `jobs` row or a dead
 *   `failed_jobs` row references it, but the publish never started so the status is still `approved`.
 *   (This is the common "0 queued / N failed" case: the pages read `approved`, not `publish_failed`.)
 *
 * Two outcomes:
 * - **default → `approved`**: the clean, re-publishable state (after fixing the cause, `drain-publish`
 *   or the worker re-pushes them).
 * - **`--reject` → `rejected`**: pull them OUT of the publish lane entirely (they never publish); a
 *   `--reason` is stamped and they can be regenerated later.
 *
 * `--flush-failed` also deletes the tenant's stuck `jobs` + dead `failed_jobs` rows (matched by the reset
 * content ids in the serialized payload) so the queued/failed banner clears. `--dry-run` lists without
 * changing anything. Never touches a `published` (live) page.
 */
class ResetPublishCommand extends Command
{
    protected $signature = 'launchpad:reset-publish {site : Site id or brand name}
        {--reject : Reject the stuck items (pull them out of the pipeline) instead of resetting to approved}
        {--to-candidate : Move stuck BLOG POSTS back to candidates (re-enter the funnel) instead of resetting to approved; pages stay approved}
        {--reason= : Reject reason (with --reject; default a generic recovery note)}
        {--flush-failed : Also delete this tenant\'s stuck queued + dead failed jobs}
        {--dry-run : List what would change without touching anything}';

    protected $description = 'Clear a tenant\'s stuck/failed publishes — reset to approved (default), --to-candidate (posts back to the funnel), or --reject out of the pipeline; optionally clear their stuck/dead jobs.';

    /** A stuck CONTENT STATUS — a publish that ran and stalled/failed (past approve, short of live). */
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

        // (a) content parked in a stuck/failed status, plus (b) approved pages whose publish job is stuck
        // in the queue (pending or failed) — the "0 queued / N failed, pages still say approved" case.
        $stuck = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('status', self::STUCK)
            ->get()
            ->concat($this->approvedWithStuckJob($site))
            ->unique('id')
            ->sortBy('updated_at')
            ->values();

        if ($stuck->isEmpty()) {
            $this->info("{$site->brand_name}: nothing stuck — no failed/queued-to-publish pages to reset.");

            return self::SUCCESS;
        }

        $reject = (bool) $this->option('reject');
        $toCandidate = (bool) $this->option('to-candidate');

        if ($reject && $toCandidate) {
            $this->error('Use either --reject or --to-candidate, not both.');

            return self::FAILURE;
        }

        $byStatus = $stuck->countBy(fn (Content $c): string => $c->status->value);
        $this->line("<info>{$site->brand_name}</info> — {$stuck->count()} stuck item(s): "
            .$byStatus->map(fn (int $n, string $s): string => "{$n} {$s}")->implode(', ').'.');

        if ($this->option('dry-run')) {
            foreach ($stuck as $c) {
                $this->line("  • {$c->title}  ·  {$c->status->value}");
            }
            $verb = match (true) {
                $reject => 'reject these (pull them out of the pipeline).',
                $toCandidate => 'move blog posts back to candidates (pages stay approved).',
                default => 'reset these to approved.',
            };
            $this->comment('Dry run — nothing changed. Re-run without --dry-run to '.$verb);

            return self::SUCCESS;
        }

        $ids = $stuck->pluck('id')->all();

        if ($toCandidate) {
            // Blog posts re-enter the funnel (candidate) so they can be re-drafted / re-categorized
            // before publishing; pages have no candidate lane, so they fall back to approved. Their
            // queued jobs are flushed either way — a leftover job on a candidate is a no-op, but
            // clearing it drops the "queued to publish" banner.
            $posts = $stuck->filter(fn (Content $c): bool => $c->kind === ContentKind::Post)->values();
            $pages = $stuck->reject(fn (Content $c): bool => $c->kind === ContentKind::Post)->values();

            if ($posts->isNotEmpty()) {
                Content::withoutGlobalScope(SiteScope::class)->whereIn('id', $posts->pluck('id')->all())
                    ->update(['status' => ContentStatus::Candidate->value, 'last_publish_error' => null]);
            }
            if ($pages->isNotEmpty()) {
                Content::withoutGlobalScope(SiteScope::class)->whereIn('id', $pages->pluck('id')->all())
                    ->update(['status' => ContentStatus::Approved->value, 'last_publish_error' => null]);
            }

            $flushed = $this->flushJobsFor($ids);

            $this->line("Moved {$posts->count()} blog post(s) back to <info>candidates</info>"
                .($pages->isNotEmpty() ? " and reset {$pages->count()} page(s) to approved (pages have no candidate lane)" : '')
                .". Cleared {$flushed['pending']} queued + {$flushed['failed']} dead job(s) — they won't publish until re-generated/re-approved.");

            return self::SUCCESS;
        }

        $target = $reject ? ContentStatus::Rejected : ContentStatus::Approved;
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
            $flushed = $this->flushJobsFor($ids);
            $this->line("Cleared {$flushed['pending']} stuck queued + {$flushed['failed']} dead failed job(s) for this tenant.");
        }

        return self::SUCCESS;
    }

    /**
     * Approved pages whose publish job is stuck in the queue — the content id appears in a pending `jobs`
     * or dead `failed_jobs` payload, but the publish never started so the row still reads `approved`.
     * ULIDs are unique, so a substring match against the pooled payloads is a safe per-tenant scope.
     *
     * @return Collection<int, Content>
     */
    private function approvedWithStuckJob(Site $site): Collection
    {
        $approved = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Approved->value)
            ->get();

        if ($approved->isEmpty()) {
            return collect();
        }

        $blob = DB::table('jobs')->pluck('payload')
            ->concat(DB::table('failed_jobs')->pluck('payload'))
            ->implode("\n");

        if ($blob === '') {
            return collect();
        }

        return $approved->filter(fn (Content $c): bool => str_contains($blob, (string) $c->id))->values();
    }

    /**
     * Delete the pending `jobs` + dead `failed_jobs` rows whose serialized payload references one of the
     * reset content ids, so a stopped page can't re-fire when the worker returns.
     *
     * @param  list<string>  $contentIds
     * @return array{pending: int, failed: int}
     */
    private function flushJobsFor(array $contentIds): array
    {
        if ($contentIds === []) {
            return ['pending' => 0, 'failed' => 0];
        }

        $match = function ($q) use ($contentIds): void {
            foreach ($contentIds as $id) {
                $q->orWhere('payload', 'like', '%'.$id.'%');
            }
        };

        return [
            'pending' => DB::table('jobs')->where($match)->delete(),
            'failed' => DB::table('failed_jobs')->where($match)->delete(),
        ];
    }
}
