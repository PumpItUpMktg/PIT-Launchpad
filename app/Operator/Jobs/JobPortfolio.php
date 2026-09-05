<?php

namespace App\Operator\Jobs;

use App\Enums\JobStatus;
use App\Filament\Pages\JobsBoard;
use App\JobCapture\Review\JobReviewActions;
use App\JobCapture\Review\JobStorefrontResolver;
use App\Models\Job;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Operator\ActiveTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The read-model behind the operator **Jobs** surface — the tenant's Job-Capture work in two views:
 * the review **queue** (what needs an operator) and the **published** body (+ the publish pipeline).
 * UI-agnostic and testable; the Filament page ({@see JobsBoard}) is thin over it
 * and routes actions through the existing {@see JobReviewActions} + publish jobs.
 *
 * The render path is HTTP-free: it reads only persisted job state (no live metrics / outbound calls),
 * matching the Console's discipline. Tenant-scoped by explicit `site_id` (drops the ambient SiteScope),
 * driven by {@see ActiveTenant}.
 */
class JobPortfolio
{
    /** Jobs an operator still has to act on, most-urgent first (Review before freshly-captured). */
    private const QUEUE = [JobStatus::Review, JobStatus::Captured, JobStatus::Enhancing];

    /** The "still trying to publish" lane shown beside the published body. */
    private const PIPELINE = [JobStatus::Approved, JobStatus::Publishing, JobStatus::PublishFailed];

    public function __construct(private JobStorefrontResolver $storefronts) {}

    /**
     * @return array{
     *     summary: array{review_backlog: int, in_capture: int, pipeline: int, published: int, failed: int},
     *     queue: list<array<string, mixed>>,
     *     published: list<array<string, mixed>>,
     *     pipeline: list<array<string, mixed>>
     * }
     */
    public function for(?string $siteId): array
    {
        if ($siteId === null) {
            return ['summary' => ['review_backlog' => 0, 'in_capture' => 0, 'pipeline' => 0, 'published' => 0, 'failed' => 0], 'queue' => [], 'published' => [], 'pipeline' => []];
        }

        $storefronts = $this->storefronts->storefronts($siteId);

        $queue = $this->scoped($siteId)
            ->whereIn('status', array_map(fn (JobStatus $s) => $s->value, self::QUEUE))
            ->with(['city', 'county', 'jobTypes'])
            ->get()
            // Review first (needs action), then by recency. Stable two-pass (PHP 8 sorts are stable):
            // order by recency, then bring Review to the front without disturbing that order.
            ->sortByDesc(fn (Job $j) => $j->performed_at ?? $j->created_at)
            ->sortBy(fn (Job $j): int => $j->status === JobStatus::Review ? 0 : 1)
            ->map(fn (Job $j): array => $this->queueRow($j))
            ->values()->all();

        $published = $this->scoped($siteId)
            ->where('status', JobStatus::Published->value)
            ->with(['city', 'county', 'jobTypes'])
            ->orderByDesc('performed_at')->orderByDesc('created_at')
            ->get()
            ->map(fn (Job $j): array => $this->publishedRow($j, $storefronts))
            ->all();

        $pipeline = $this->scoped($siteId)
            ->whereIn('status', array_map(fn (JobStatus $s) => $s->value, self::PIPELINE))
            ->with(['city', 'county', 'jobTypes'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Job $j): array => $this->publishedRow($j, $storefronts))
            ->all();

        $counts = $this->scoped($siteId)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'summary' => [
                'review_backlog' => (int) ($counts[JobStatus::Review->value] ?? 0),
                'in_capture' => (int) ($counts[JobStatus::Captured->value] ?? 0) + (int) ($counts[JobStatus::Enhancing->value] ?? 0),
                'pipeline' => count($pipeline),
                'published' => (int) ($counts[JobStatus::Published->value] ?? 0),
                'failed' => (int) ($counts[JobStatus::PublishFailed->value] ?? 0),
            ],
            'queue' => $queue,
            'published' => $published,
            'pipeline' => $pipeline,
        ];
    }

    /** @return Builder<Job> */
    private function scoped(string $siteId)
    {
        return Job::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId);
    }

    /** @return array<string, mixed> */
    private function queueRow(Job $job): array
    {
        return [
            'id' => (string) $job->id,
            'title' => $this->title($job),
            'client' => (string) $job->client_name_display,
            'place' => $this->place($job),
            'services' => $job->jobTypes->pluck('label')->all(),
            'photos' => is_array($job->photos) ? count($job->photos) : 0,
            'performed_at' => $job->performed_at?->toFormattedDateString(),
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'has_draft' => $job->hasDraft(),
        ];
    }

    /**
     * @param  Collection<int, Location>  $storefronts
     * @return array<string, mixed>
     */
    private function publishedRow(Job $job, Collection $storefronts): array
    {
        return [
            'id' => (string) $job->id,
            'title' => $this->title($job),
            'client' => (string) $job->client_name_display,
            'place' => $this->place($job),
            'services' => $job->jobTypes->pluck('label')->all(),
            'storefront' => $this->storefronts->resolve($job, $storefronts),
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'wp_post_id' => $job->wp_post_id,
            'error' => $job->last_publish_error !== null ? (string) $job->last_publish_error : null,
            'performed_at' => $job->performed_at?->toFormattedDateString(),
        ];
    }

    private function title(Job $job): string
    {
        $title = trim((string) $job->post_title);
        if ($title !== '') {
            return $title;
        }
        $city = $job->city?->name;

        return $city !== null ? "Job in {$city}" : 'Untitled job';
    }

    private function place(Job $job): string
    {
        return trim(implode(' · ', array_filter([$job->city?->name, $job->county?->name])), ' ·') ?: '—';
    }
}
