<?php

namespace App\Filament\Console\Pages;

use App\Enums\JobStatus;
use App\Filament\Concerns\ReviewsJobs;
use App\JobCapture\Review\JobReviewActions;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\TenantStorage;
use BackedEnum;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Console → Jobs → Review: the operator's Job Capture review screen (§8), thin over {@see JobReviewActions}.
 * For each job awaiting a decision it shows the three photos (primary selectable), the raw / operator-source
 * / AI-enhanced descriptions side by side, the resolved job types + city/county, and the jittered public
 * point — with edit-in-place, re-enhance, approve, reject, and re-place. The actions live in the shared
 * {@see ReviewsJobs} workbench (also the admin Build → Jobs board); this page keeps only its queue read +
 * tenant guard. Nothing here pushes to WordPress directly: approve enqueues the §9 publish.
 */
class JobReview extends ConsolePage
{
    use ReviewsJobs, WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationLabel = 'Job Review';

    protected static string|\UnitEnum|null $navigationGroup = 'Jobs';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'job-review';

    protected string $view = 'filament.console.job-review';

    /**
     * The jobs awaiting a decision for the active site — review first, then captured/enhancing (including a
     * job stranded at `enhancing` by a failed model call, so it stays visible and re-enhanceable). Presented
     * for the blade (descriptions, resolved geography, photos with URLs + alt, primary index).
     *
     * @return list<array<string, mixed>>
     */
    public function getReviewJobsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereIn('status', [JobStatus::Review->value, JobStatus::Captured->value, JobStatus::Enhancing->value])
            ->with(['city', 'county', 'jobTypes'])
            ->orderByRaw('CASE status WHEN ? THEN 0 ELSE 1 END', [JobStatus::Review->value])
            ->latest()
            ->get()
            ->map(fn (Job $job): array => $this->present($job))
            ->all();
    }

    /** A job in a site the operator may see — the guard every mutating action runs first. */
    protected function ownedJob(string $id): ?Job
    {
        $job = Job::withoutGlobalScope(SiteScope::class)->whereKey($id)->first();

        if ($job === null || ! $this->user()->canSeeSite((string) $job->site_id)) {
            return null;
        }

        return $job;
    }

    /** @return array<string, mixed> */
    private function present(Job $job): array
    {
        $primary = $job->primary_photo_index;
        $photos = is_array($job->photos) ? $job->photos : [];

        return [
            'id' => (string) $job->id,
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'client' => (string) $job->client_name_display,
            'city' => $job->job_city_id !== null ? $job->city->name : null,
            'county' => $job->job_county_id !== null ? $job->county->name : null,
            'address' => (string) $job->address_true,   // operator-only: the typed address the job is placed at ('' = GPS capture)
            'pushed' => $job->wp_post_id !== null,
            'lat' => $job->lat_jittered !== null ? (float) $job->lat_jittered : null,
            'lng' => $job->lng_jittered !== null ? (float) $job->lng_jittered : null,
            'job_types' => $job->jobTypes->pluck('label')->all(),
            'raw' => (string) $job->raw_description,
            'source' => (string) ($job->source_description ?? $job->raw_description),
            'enhanced' => (string) $job->enhanced_description,
            'title' => (string) $job->post_title,
            'meta' => (string) $job->meta_description,
            'has_draft' => $job->hasDraft(),
            'photos' => array_map(fn (array $p, int $i): array => [
                'url' => $this->url((string) $p['r2_key']),
                'alt' => (string) ($p['alt'] ?? ''),
                'primary' => $i === $primary,
            ], $photos, array_keys($photos)),
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
