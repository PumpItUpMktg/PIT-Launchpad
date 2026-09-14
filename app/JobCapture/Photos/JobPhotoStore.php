<?php

namespace App\JobCapture\Photos;

use App\JobCapture\Geography\Jitter;
use App\Models\Job;
use App\Models\Site;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The single path that persists a job's photos to R2 (§ Job Capture). Every capture path — tech PWA, operator
 * backfill, add-to-existing, and the reusable library — goes through here so a photo is ALWAYS scrubbed of its
 * source metadata ({@see ExifGeotagger::scrub()}) and, when the job has a point, geotagged to the job's PUBLIC
 * (jittered) point before it's stored. A device's real GPS, an editing app's XMP location, a text message's
 * leftovers: none of it survives into the stored bytes. An image that can't be decoded is refused (skipped and
 * logged) rather than stored with whatever it carried.
 *
 * The jittered point is the same one the public map uses; if it hasn't been computed yet (photos are stored
 * before the geography resolver runs on the operator path) it's computed and persisted here, once — the
 * resolver then leaves it alone, so the point stays stable. A job with no true point yet is stored scrubbed
 * but ungeotagged; {@see restamp()} writes the point in later (the resolver calls it once the point exists,
 * and a re-placed job calls it again with its new point).
 */
final class JobPhotoStore
{
    public function __construct(
        private readonly TenantStorage $storage,
        private readonly ExifGeotagger $geotagger,
        private readonly Jitter $jitter,
    ) {}

    /**
     * Scrub + stamp + store up to $max photos; returns the photo rows for the job's `photos` JSON. A photo
     * that can't be decoded is skipped (never stored as-is).
     *
     * @param  list<array{bytes: string, filename?: string}>  $photos
     * @param  int  $startIndex  the filename counter offset (for appends)
     * @return list<array{r2_key: string, hash: string, geotagged: bool, scrubbed: bool, lat?: float, lng?: float}>
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

            $clean = $this->geotagger->scrub($photo['bytes'], $point['lat'] ?? null, $point['lng'] ?? null);
            if ($clean === null) {
                Log::warning('Job photo refused: not a decodable image — never stored with its source metadata.', [
                    'job_id' => $job->id, 'filename' => $filename,
                ]);

                continue;
            }

            $stored[] = $this->row($this->storage->putForJob($site, $job->id, $filename, $clean), $clean, $point);
        }

        return $stored;
    }

    /**
     * Re-scrub and re-stamp the job's ALREADY-STORED photos with its current public point, overwriting each
     * object under the same key (the live page serves the same URL). Used when the point arrives after the
     * photos (the geography resolver) and when a job is re-placed. With $onlyUnstamped, photos already
     * scrubbed and geotagged are left alone. Returns how many were rewritten.
     */
    public function restamp(Job $job, bool $onlyUnstamped = false): int
    {
        $rows = is_array($job->photos) ? $job->photos : [];
        if ($rows === []) {
            return 0;
        }

        $point = $this->publicPoint($job);
        $disk = Storage::disk(TenantStorage::DISK);
        $rewritten = 0;

        foreach ($rows as $i => $row) {
            if ($onlyUnstamped && ($row['scrubbed'] ?? false) === true && ($row['geotagged'] ?? false) === true) {
                continue;
            }

            $bytes = $disk->get($row['r2_key']);
            if (! is_string($bytes) || $bytes === '') {
                continue;
            }

            $clean = $this->geotagger->scrub($bytes, $point['lat'] ?? null, $point['lng'] ?? null);
            if ($clean === null) {
                Log::warning('Job photo could not be re-scrubbed: not a decodable image.', ['job_id' => $job->id, 'r2_key' => $row['r2_key']]);

                continue;
            }

            $disk->put($row['r2_key'], $clean);
            $rows[$i] = [...$row, ...$this->row($row['r2_key'], $clean, $point)];
            $rewritten++;
        }

        if ($rewritten > 0) {
            $job->forceFill(['photos' => $rows])->save();
        }

        return $rewritten;
    }

    /**
     * @param  array{lat: float, lng: float}|null  $point
     * @return array{r2_key: string, hash: string, geotagged: bool, scrubbed: bool, lat?: float, lng?: float}
     */
    private function row(string $key, string $bytes, ?array $point): array
    {
        $row = [
            'r2_key' => $key,
            'hash' => hash('sha256', $bytes),
            'geotagged' => $point !== null,
            'scrubbed' => true,
        ];
        if ($point !== null) {
            $row['lat'] = $point['lat'];
            $row['lng'] = $point['lng'];
        }

        return $row;
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
