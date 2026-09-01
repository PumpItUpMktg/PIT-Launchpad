<?php

namespace App\JobCapture\Photos;

use App\JobCapture\Geography\GeographyResolver;
use App\JobCapture\Geography\Jitter;
use App\Models\Job;
use App\Models\Site;
use App\Publishing\TenantStorage;

/**
 * The single path that persists a job's photos to R2 (§ Job Capture). Every capture path — tech PWA, operator
 * backfill, add-to-existing, and (later) the reusable library — goes through here so a photo is ALWAYS geotagged
 * to the job's PUBLIC (jittered) point before it's stored: the images carry the approximate service location,
 * and a device's real GPS never survives into the stored bytes.
 *
 * The jittered point is the same one the public map uses; if it hasn't been computed yet (photos are stored
 * before {@see GeographyResolver} runs on the operator path) it's computed and
 * persisted here, once — the resolver then leaves it alone, so the point stays stable. A job with no true point
 * yet (nothing to jitter) is stored ungeotagged and can be re-stamped once it geocodes.
 */
final class JobPhotoStore
{
    public function __construct(
        private readonly TenantStorage $storage,
        private readonly ExifGeotagger $geotagger,
        private readonly Jitter $jitter,
    ) {}

    /**
     * Stamp + store up to $max photos; returns the photo rows for the job's `photos` JSON.
     *
     * @param  list<array{bytes: string, filename?: string}>  $photos
     * @param  int  $startIndex  the filename counter offset (for appends)
     * @return list<array{r2_key: string, hash: string, geotagged: bool, lat?: float, lng?: float}>
     */
    public function store(Site $site, Job $job, array $photos, int $max, int $startIndex = 0): array
    {
        if ($photos === []) {
            return [];
        }

        $point = $this->publicPoint($job);

        $stored = [];
        foreach (array_slice($photos, 0, $max) as $i => $photo) {
            $filename = $photo['filename'] ?? ($startIndex + $i + 1).'.jpg';
            $bytes = $photo['bytes'];

            $geotagged = false;
            if ($point !== null) {
                $stamped = $this->geotagger->stamp($bytes, $point['lat'], $point['lng']);
                $geotagged = $stamped !== $bytes;
                $bytes = $stamped;
            }

            $row = [
                'r2_key' => $this->storage->putForJob($site, $job->id, $filename, $bytes),
                'hash' => hash('sha256', $bytes),
                'geotagged' => $geotagged,
            ];
            if ($point !== null) {
                $row['lat'] = $point['lat'];
                $row['lng'] = $point['lng'];
            }
            $stored[] = $row;
        }

        return $stored;
    }

    /**
     * The job's jittered public point — computing and persisting it once (from the true point) if geography
     * resolution hasn't yet. Null when there's no true point to jitter.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function publicPoint(Job $job): ?array
    {
        if ($job->lat_jittered !== null && $job->lng_jittered !== null) {
            return ['lat' => (float) $job->lat_jittered, 'lng' => (float) $job->lng_jittered];
        }
        if ($job->lat_true === null || $job->lng_true === null) {
            return null;
        }

        $jittered = $this->jitter->apply((float) $job->lat_true, (float) $job->lng_true);
        $job->forceFill(['lat_jittered' => $jittered['lat'], 'lng_jittered' => $jittered['lng']])->save();

        return $jittered;
    }
}
