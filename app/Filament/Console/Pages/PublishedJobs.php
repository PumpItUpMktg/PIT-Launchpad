<?php

namespace App\Filament\Console\Pages;

use App\Enums\JobStatus;
use App\JobCapture\Review\JobStorefrontResolver;
use App\Jobs\PublishJob;
use App\Jobs\UnpublishJob;
use App\Models\Job;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Publishing\TenantStorage;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Console → Jobs → Published: the after-review home for a job. The review queue only shows jobs still
 * awaiting a decision, so once an operator approves one it used to drop off every surface — invisible while
 * it published (or got stuck). This page closes that gap: live jobs as cards, plus a "publish pipeline"
 * lane for the post-review states that aren't live yet (approved / publishing / publish_failed) with a
 * one-click retry, so a job that didn't reach WordPress is visible and re-pushable rather than lost.
 */
class PublishedJobs extends ConsolePage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationLabel = 'Published Jobs';

    protected static string|\UnitEnum|null $navigationGroup = 'Jobs';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'published-jobs';

    protected string $view = 'filament.console.published-jobs';

    /** The post-review states that are NOT live yet — the "still trying to publish" lane. */
    private const PIPELINE = [
        JobStatus::Approved->value,
        JobStatus::Publishing->value,
        JobStatus::PublishFailed->value,
    ];

    /**
     * Live-on-WordPress jobs for the active site, newest first — the card grid.
     *
     * @return list<array<string, mixed>>
     */
    public function getPublishedJobsProperty(): array
    {
        return $this->cards([JobStatus::Published->value]);
    }

    /**
     * Post-review jobs not yet live (approved / publishing / publish_failed) — surfaced so a job that
     * stalled on the way to WordPress is visible and retryable instead of silently gone.
     *
     * @return list<array<string, mixed>>
     */
    public function getPipelineJobsProperty(): array
    {
        return $this->cards(self::PIPELINE);
    }

    /** Re-dispatch the §9 WordPress push — for a stuck/failed job, or to re-push a live one (idempotent). */
    public function retryPublish(string $id): void
    {
        if (! $this->can(Capability::PublishContent)) {
            return;
        }
        $job = $this->ownedJob($id);
        // Any post-review job (pipeline OR already live) may be re-pushed; the push is idempotent by ULID.
        if ($job === null || ! in_array($job->status->value, [...self::PIPELINE, JobStatus::Published->value], true)) {
            return;
        }

        PublishJob::dispatch($job->id);
        Notification::make()->title('Re-publishing to WordPress — this refreshes shortly.')->success()->send();
    }

    /**
     * Send a job back to the review queue to edit it. Status → review (it appears in Job Review with its
     * write-up for editing); the wp_post_id is kept, so re-approving republishes the SAME WordPress post.
     */
    public function editInReview(string $id): void
    {
        if (! $this->can(Capability::EditContent)) {
            return;
        }
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        $job->forceFill(['status' => JobStatus::Review])->save();
        Notification::make()->title('Sent to Job Review for editing — re-approve to republish.')->success()->send();
    }

    /**
     * Take a live job DOWN from WordPress (pull the post) and park it as approved-but-not-live, so it leaves
     * the published grid but stays re-pushable. Best-effort pull-down via the §2 UnpublishJob (idempotent).
     */
    public function takeDown(string $id): void
    {
        if (! $this->can(Capability::PublishContent)) {
            return;
        }
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        if ($job->wp_post_id !== null) {
            UnpublishJob::dispatch($job->id);
        }
        $job->forceFill(['status' => JobStatus::Approved])->save();
        Notification::make()->title('Taking the job down from WordPress.')->success()->send();
    }

    /**
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    private function cards(array $statuses): array
    {
        if ($this->siteId === null) {
            return [];
        }

        $resolver = app(JobStorefrontResolver::class);
        $storefronts = $resolver->storefronts($this->siteId);

        return Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereIn('status', $statuses)
            ->with(['city', 'county', 'jobTypes'])
            ->latest('updated_at')
            ->get()
            ->map(fn (Job $job): array => $this->present($job, $resolver, $storefronts))
            ->all();
    }

    /** A job in a site the operator may see — the guard the retry action runs first. */
    protected function ownedJob(string $id): ?Job
    {
        $job = Job::withoutGlobalScope(SiteScope::class)->whereKey($id)->first();

        if ($job === null || ! $this->user()->canSeeSite((string) $job->site_id)) {
            return null;
        }

        return $job;
    }

    /**
     * @param  Collection<int, Location>  $storefronts
     * @return array<string, mixed>
     */
    private function present(Job $job, JobStorefrontResolver $resolver, Collection $storefronts): array
    {
        $photos = is_array($job->photos) ? $job->photos : [];
        $primary = $photos[$job->primary_photo_index] ?? ($photos[0] ?? null);

        return [
            'id' => (string) $job->id,
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'title' => (string) ($job->post_title ?: $job->client_name_display),
            'client' => (string) $job->client_name_display,
            'city' => $job->job_city_id !== null ? $job->city->name : null,
            'county' => $job->job_county_id !== null ? $job->county->name : null,
            'job_types' => $job->jobTypes->pluck('label')->all(), // service-type pills
            'storefront' => $resolver->resolve($job, $storefronts), // storefront it references (territory)
            'meta' => (string) $job->meta_description,
            'photo' => is_array($primary) ? $this->url((string) $primary['r2_key']) : '',
            'wp_post_id' => $job->wp_post_id,
            'error' => (string) $job->last_publish_error,
            'when' => $job->updated_at instanceof Carbon ? $job->updated_at->diffForHumans() : null,
        ];
    }

    private function url(string $key): string
    {
        if ($key === '') {
            return '';
        }
        try {
            return Storage::disk(TenantStorage::DISK)->url($key);
        } catch (Throwable) {
            return '';
        }
    }
}
