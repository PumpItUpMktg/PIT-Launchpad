<?php

namespace App\JobCapture\Review;

use App\JobCapture\Photos\JobPhotoStore;
use App\Models\Job;

/**
 * Adds photos to an EXISTING job from the review screen — the path for a CSV-imported or text-only backfill
 * job that had no photos at capture, and for a walk-in the operator photographs later. Stores each image via
 * {@see JobPhotoStore} (geotagged to the job's jittered point) under the per-job R2 prefix and appends to the
 * photo set, capped at the same {@see Job::MAX_PHOTOS} total. New photos carry no alt text yet — a re-enhance
 * fills them.
 */
final class JobPhotoAttacher
{
    public function __construct(private readonly JobPhotoStore $photos) {}

    /**
     * Append photos to the job (up to the per-job cap total). Returns how many were actually added.
     *
     * @param  list<array{bytes: string, filename?: string}>  $photos
     */
    public function attach(Job $job, array $photos): int
    {
        $existing = is_array($job->photos) ? $job->photos : [];
        $room = Job::MAX_PHOTOS - count($existing);
        if ($room <= 0 || $photos === []) {
            return 0;
        }

        $added = $this->photos->store($job->site, $job, $photos, $room, count($existing));

        $job->forceFill(['photos' => [...$existing, ...$added]])->save();

        return count($added);
    }
}
