<?php

namespace App\Local\Proof;

use App\Enums\JobStatus;
use App\JobCapture\Publishing\JobMetaBlobAssembler;
use App\Models\Job;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * DB-backed recent-jobs source: the site's PUBLISHED Job Capture jobs that fall near a location — matched
 * by served-town/city name membership OR within the location's coverage radius of its center (using the
 * public JITTERED coordinates only, never the true point). Each is mapped to a public-safe {@see LocalJob};
 * empty ⇒ the section omits (no header over nothing).
 *
 * Mirrors the `jobcapture.radius` entity gate (Published + site-scoped) so the page's proof gate and the
 * rendered cards agree. Multi-shop safe: a location only shows work in the towns/radius it actually serves,
 * so one tenant's shops don't cross-pollinate each other's recent work.
 */
final class JobCaptureLocalJobs implements LocalJobProvider
{
    /** Coverage radius (miles) used when the location carries no preset. */
    private const DEFAULT_RADIUS_MILES = 20.0;

    /** Upper bound returned; the caller slices to its own display cap. */
    private const MAX = 6;

    private const EARTH_RADIUS_MILES = 3958.8;

    /** @return list<LocalJob> */
    public function for(Location $location): array
    {
        $jobs = Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('status', JobStatus::Published->value)
            ->with(['city', 'jobTypes'])
            ->get();

        if ($jobs->isEmpty()) {
            return [];
        }

        $townKeys = $this->townKeys($location);
        $radius = (float) ($location->coverage_radius ?? self::DEFAULT_RADIUS_MILES);
        $lat = $location->lat !== null ? (float) $location->lat : null;
        $lng = $location->lng !== null ? (float) $location->lng : null;

        return $jobs
            ->filter(fn (Job $job): bool => $this->isNear($job, $townKeys, $lat, $lng, $radius))
            ->sortByDesc(fn (Job $job): int => ($job->performed_at ?? $job->created_at)?->getTimestamp() ?? 0)
            ->take(self::MAX)
            ->map(fn (Job $job): LocalJob => $this->toLocalJob($job))
            ->values()
            ->all();
    }

    /**
     * The location's served-town + own-city names, normalized for membership matching.
     *
     * @return array<string, true>
     */
    private function townKeys(Location $location): array
    {
        $keys = [];
        $own = $location->cityState()['city'];
        if ($own !== '') {
            $keys[$this->key($own)] = true;
        }
        foreach ($location->served_towns ?? [] as $town) {
            $name = trim((string) ($town['name'] ?? ''));
            if ($name !== '') {
                $keys[$this->key($name)] = true;
            }
        }

        return $keys;
    }

    /**
     * A job is "near" the location when its resolved city is one the location serves, OR its public
     * (jittered) point is within the coverage radius of the location center.
     *
     * @param  array<string, true>  $townKeys
     */
    private function isNear(Job $job, array $townKeys, ?float $lat, ?float $lng, float $radius): bool
    {
        $city = $job->job_city_id !== null ? (string) $job->city->name : '';
        if ($city !== '' && isset($townKeys[$this->key($city)])) {
            return true;
        }

        if ($lat === null || $lng === null || $job->lat_jittered === null || $job->lng_jittered === null) {
            return false;
        }

        return $this->haversineMiles($lat, $lng, (float) $job->lat_jittered, (float) $job->lng_jittered) <= $radius;
    }

    private function toLocalJob(Job $job): LocalJob
    {
        $description = trim((string) $job->meta_description);
        if ($description === '') {
            $description = trim(strip_tags((string) $job->enhanced_description));
        }

        return new LocalJob(
            title: $job->publicTitle(),
            description: $description,
            photos: $this->photoUrls($job),
            town: $job->job_city_id !== null ? (string) $job->city->name : '',
            service: $job->jobTypes->first()?->label,
            date: ($job->performed_at ?? $job->created_at)?->format('M Y'),
        );
    }

    /**
     * Public photo URLs (primary slot first) resolved from R2 keys — served from R2/CDN, never the WP
     * media library. Falls back to the raw key when no public URL is configured (e.g. tests), matching
     * {@see JobMetaBlobAssembler}.
     *
     * @return list<string>
     */
    private function photoUrls(Job $job): array
    {
        $photos = is_array($job->photos) ? $job->photos : [];
        $primary = $job->primary_photo_index;

        // Primary slot first so the card's lead image matches the published post.
        uksort($photos, fn ($a, $b): int => ($a === $primary ? -1 : 0) <=> ($b === $primary ? -1 : 0));

        $urls = [];
        foreach ($photos as $photo) {
            $key = trim($photo['r2_key']);
            if ($key !== '') {
                $urls[] = $this->url($key);
            }
        }

        return $urls;
    }

    private function url(string $key): string
    {
        try {
            return Storage::disk(TenantStorage::DISK)->url($key);
        } catch (Throwable) {
            return $key;
        }
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function haversineMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_MILES * 2 * asin(min(1.0, sqrt($a)));
    }
}
