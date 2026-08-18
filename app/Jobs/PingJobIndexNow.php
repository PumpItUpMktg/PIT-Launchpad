<?php

namespace App\Jobs;

use App\Integrations\IndexNow\IndexNowSubmitter;
use App\JobCapture\Publishing\JobPublisher;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fire-and-forget IndexNow ping for one freshly-published JOB page — the Job Capture twin of
 * {@see PingIndexNow}. Dispatched by {@see JobPublisher} on publish success so
 * Bing/Yandex/etc are told to crawl the job URL immediately. Off the publish critical path (its own queued
 * job), `tries = 1`, and swallow-all: a stale plugin or unreachable IndexNow endpoint can never affect
 * publishing. Stamps `indexnow_submitted_at` on success so the Published Jobs card shows a "Submitted to
 * Bing" pill.
 */
class PingJobIndexNow implements ShouldQueue
{
    use Queueable;

    /** No auto-retry — a missed ping is harmless (the daily/manual full-site submit covers it). */
    public int $tries = 1;

    public function __construct(public readonly string $jobId) {}

    public function handle(IndexNowSubmitter $submitter): void
    {
        $job = Job::withoutGlobalScope(SiteScope::class)->with('site')->find($this->jobId);
        $site = $job?->site;
        if ($job === null || $site === null) {
            return;
        }

        $url = $job->publicUrl($site->domain_url);
        if ($url === null) {
            return;
        }

        $result = $submitter->submitUrl($site, $url);

        if ($result['ok']) {
            $job->forceFill(['indexnow_submitted_at' => now()])->save();
        } else {
            Log::info('Job IndexNow ping skipped/failed', ['url' => $url, 'reason' => $result['reason'] ?? null]);
        }
    }
}
