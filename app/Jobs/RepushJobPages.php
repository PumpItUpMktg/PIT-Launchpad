<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\JobCapture\Publishing\JobPageTargets;
use App\Jobs\Concerns\OnPublishLane;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Re-push the pages that show newly published jobs (§ Job Capture): the jobs' service pages and the
 * location pages within range. Debounced per site — unique while queued, dispatched with a delay — so a
 * batch of jobs publishing together (an import landing) re-pushes each affected page ONCE after the batch,
 * not once per job. Covers every job published since the last run (a cache marker), so nothing is missed
 * when several publishes collapse into one run.
 */
class RepushJobPages implements ShouldBeUnique, ShouldQueue
{
    use OnPublishLane;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public readonly string $siteId)
    {
        $this->onPublishLane();
    }

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    /** Queue one run for the site after the configured delay; a run already waiting absorbs this one. */
    public static function after(string $siteId): void
    {
        $minutes = (int) config('launchpad.publish.job_repush_delay_minutes', 5);
        if ($minutes <= 0) {
            return;
        }
        self::dispatch($siteId)->delay(now()->addMinutes($minutes));
    }

    public static function markerKey(string $siteId): string
    {
        return "job_repush:last:{$siteId}";
    }

    public function handle(JobPageTargets $targets): void
    {
        $key = self::markerKey($this->siteId);
        $since = Cache::get($key);
        $since = is_string($since) ? Carbon::parse($since) : now()->subDay();
        $now = now();

        $jobs = Job::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)
            ->where('status', JobStatus::Published->value)
            ->where('updated_at', '>=', $since)
            ->with('jobTypes')
            ->get();
        Cache::put($key, $now->toIso8601String(), now()->addDays(30));

        foreach ($targets->for($this->siteId, $jobs) as $contentId) {
            PublishContent::dispatch($contentId);
        }
    }
}
