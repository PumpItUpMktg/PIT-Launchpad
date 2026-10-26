<?php

namespace App\Local\Proof;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\JobType;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use Illuminate\Support\Str;

/**
 * DB-backed recent-jobs source for a SERVICE page: the site's PUBLISHED Job Capture jobs done for this
 * service, newest first (by the work date, else capture time). A job matches the service through the
 * job-type vocabulary's catalog link (`job_types.service_id`) or, for a label typed before the catalog
 * mirrored it, the label's slug equal to the service name's. Site-wide — a service page has no location —
 * and mapped to the same public-safe {@see LocalJob} card the location pages use. Empty ⇒ the section omits.
 */
final class JobCaptureServiceJobs implements ServiceJobProvider
{
    /** Upper bound returned; the caller slices to its own display cap. */
    private const MAX = 6;

    public function __construct(private readonly JobCaptureLocalJobs $cards) {}

    /** @return list<LocalJob> */
    public function for(Service $service): array
    {
        $typeIds = JobType::withoutGlobalScope(SiteScope::class)->where('site_id', $service->site_id)->where('service_id', $service->id)->pluck('id')->all();
        $slug = Str::slug((string) $service->name);

        return Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $service->site_id)
            ->where('status', JobStatus::Published->value)
            ->whereHas('jobTypes', function ($q) use ($typeIds, $slug): void {
                $q->where(function ($w) use ($typeIds, $slug): void {
                    if ($typeIds !== []) {
                        $w->whereIn('job_type_id', $typeIds);
                    }
                    if ($slug !== '') {
                        $typeIds !== [] ? $w->orWhere('slug', $slug) : $w->where('slug', $slug);
                    }
                    if ($typeIds === [] && $slug === '') {
                        $w->whereRaw('1 = 0');
                    }
                });
            })
            ->with(['city', 'jobTypes'])
            ->get()
            ->sortByDesc(fn (Job $job): int => ($job->performed_at ?? $job->created_at)?->getTimestamp() ?? 0)
            ->take(self::MAX)
            ->map(fn (Job $job): LocalJob => $this->cards->card($job))
            ->values()
            ->all();
    }
}
