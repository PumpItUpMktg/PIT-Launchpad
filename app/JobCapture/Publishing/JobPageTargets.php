<?php

namespace App\JobCapture\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Local\Proof\JobCaptureServiceJobs;
use App\Models\Content;
use App\Models\Job;
use App\Models\JobType;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Publishing\Blocks\ServiceAreaResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which live pages would show a set of newly published jobs (§ Job Capture): the SERVICE page of each job's
 * service(s) — matched the way {@see JobCaptureServiceJobs} matches — and the LOCATION
 * pages (office hubs by the office's point, town pages by the town's centroid) within the neighbour radius
 * of a job's public point, the way the location page itself selects nearby jobs. Returns Content ids, each
 * once, so the caller re-pushes every affected page exactly once.
 */
final class JobPageTargets
{
    private const EARTH_RADIUS_MILES = 3958.8;

    public function __construct(private readonly ServiceAreaResolver $serviceAreas) {}

    /**
     * @param  Collection<int, Job>  $jobs  published jobs on one site
     * @return list<string> content ids
     */
    public function for(string $siteId, Collection $jobs): array
    {
        if ($jobs->isEmpty()) {
            return [];
        }
        $ids = [];
        foreach ($this->servicePages($siteId, $jobs) as $id) {
            $ids[$id] = true;
        }
        foreach ($this->locationPages($siteId, $jobs) as $id) {
            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    /** @param  Collection<int, Job>  $jobs  @return list<string> */
    private function servicePages(string $siteId, Collection $jobs): array
    {
        $typeIds = [];
        $slugs = [];
        foreach ($jobs as $job) {
            foreach ($job->jobTypes as $t) {
                if ($t->job_type_id !== null) {
                    $typeIds[(string) $t->job_type_id] = true;
                }
                $slugs[(string) $t->slug] = true;
            }
        }
        $serviceIds = [];
        if ($typeIds !== []) {
            foreach (JobType::withoutGlobalScope(SiteScope::class)->whereIn('id', array_keys($typeIds))->whereNotNull('service_id')->pluck('service_id') as $sid) {
                $serviceIds[(string) $sid] = true;
            }
        }
        if ($slugs !== []) {
            foreach (Service::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->get(['id', 'name']) as $service) {
                if (isset($slugs[Str::slug((string) $service->name)])) {
                    $serviceIds[(string) $service->id] = true;
                }
            }
        }
        if ($serviceIds === []) {
            return [];
        }

        return Content::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)
            ->where('kind', ContentKind::Page->value)->where('page_type', PageType::Service->value)
            ->where('status', ContentStatus::Published->value)
            ->whereIn('primary_service_id', array_keys($serviceIds))
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    /** @param  Collection<int, Job>  $jobs  @return list<string> */
    private function locationPages(string $siteId, Collection $jobs): array
    {
        $points = [];
        foreach ($jobs as $job) {
            if ($job->lat_jittered !== null && $job->lng_jittered !== null) {
                $points[] = [(float) $job->lat_jittered, (float) $job->lng_jittered];
            }
        }
        if ($points === []) {
            return [];
        }
        $radius = (float) config('launchpad.link_plan.neighbour_radius_miles', 20.0);
        $pages = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)
            ->where('kind', ContentKind::Page->value)->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'location_id', 'parent_location_id', 'geo_id']);
        if ($pages->isEmpty()) {
            return [];
        }
        $offices = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->get(['id', 'lat', 'lng'])->keyBy('id');

        $out = [];
        foreach ($pages as $page) {
            if ($page->location_id !== null) {
                // An office hub measures from the office.
                $office = $offices->get($page->location_id);
                $lat = $office?->lat !== null ? (float) $office->lat : null;
                $lng = $office?->lng !== null ? (float) $office->lng : null;
            } else {
                // A town page measures from the town's own centroid, as the page itself does.
                ['lat' => $lat, 'lng' => $lng] = $this->serviceAreas->subjectCentroid($siteId, $page->geo_id, (string) $page->title);
            }
            if ($lat === null || $lng === null) {
                continue;
            }
            foreach ($points as [$jlat, $jlng]) {
                if (self::miles($lat, $lng, $jlat, $jlng) <= $radius) {
                    $out[] = (string) $page->id;
                    break;
                }
            }
        }

        return $out;
    }

    private static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_MILES * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
