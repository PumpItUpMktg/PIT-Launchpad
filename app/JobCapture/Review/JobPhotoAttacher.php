<?php

namespace App\JobCapture\Review;

use App\Models\Job;
use App\Publishing\TenantStorage;

/**
 * Adds photos to an EXISTING job from the review screen — the path for a CSV-imported or text-only backfill
 * job that had no photos at capture, and for a walk-in the operator photographs later. Stores each image
 * under the per-job R2 prefix (same as capture) and appends to the job's photo set, capped at the same
 * {@see Job::MAX_PHOTOS} total. New photos carry no alt text yet — a re-enhance fills them.
 */
final class JobPhotoAttacher
{
    public function __construct(private readonly TenantStorage $storage) {}

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

        $site = $job->site;
        $added = [];
        foreach (array_slice($photos, 0, $room) as $i => $photo) {
            $filename = $photo['filename'] ?? (count($existing) + $i + 1).'.jpg';
            $added[] = [
                'r2_key' => $this->storage->putForJob($site, $job->id, $filename, $photo['bytes']),
                'hash' => hash('sha256', $photo['bytes']),
            ];
        }

        $job->forceFill(['photos' => [...$existing, ...$added]])->save();

        return count($added);
    }
}
