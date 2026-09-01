<?php

namespace App\JobCapture\Photos;

use App\Models\Job;
use App\Models\LibraryPhoto;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;

/**
 * Attaches reusable library photos to a job (§ Job Capture). Each selected {@see LibraryPhoto}'s source bytes
 * are copied through {@see JobPhotoStore}, which stamps a per-job copy geotagged to THIS job's jittered point —
 * so the same library image legitimately carries a different job's approximate location. The job's photo rows
 * record `source_library_photo_id` for provenance. Capped at the per-job {@see Job::MAX_PHOTOS} total, and
 * scoped to the job's own account so one tenant can't pull another's library.
 */
final class LibraryPhotoAttacher
{
    public function __construct(private readonly JobPhotoStore $photos) {}

    /**
     * @param  list<string>  $libraryPhotoIds
     * @return int how many were actually attached
     */
    public function attach(Job $job, array $libraryPhotoIds): int
    {
        $existing = is_array($job->photos) ? $job->photos : [];
        $room = Job::MAX_PHOTOS - count($existing);
        if ($room <= 0 || $libraryPhotoIds === []) {
            return 0;
        }

        $account = $job->site?->account;
        if ($account === null) {
            return 0;
        }

        // Preserve the caller's selection order, restricted to this account's library and the remaining room.
        $library = LibraryPhoto::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $libraryPhotoIds)
            ->get()->keyBy('id');

        $incoming = [];
        $sourceIds = [];
        foreach ($libraryPhotoIds as $id) {
            if (count($incoming) >= $room) {
                break;
            }
            $photo = $library->get($id);
            if ($photo === null) {
                continue;
            }
            $bytes = Storage::disk(TenantStorage::DISK)->get($photo->r2_key);
            if ($bytes === null || $bytes === '') {
                continue;
            }
            $incoming[] = ['bytes' => $bytes, 'filename' => $photo->original_filename ?? ($photo->id.'.jpg')];
            $sourceIds[] = (string) $photo->id;
        }

        if ($incoming === []) {
            return 0;
        }

        $rows = $this->photos->store($job->site, $job, $incoming, $room, count($existing));
        foreach ($rows as $i => &$row) {
            $row['source_library_photo_id'] = $sourceIds[$i] ?? null;
        }
        unset($row);

        $job->forceFill(['photos' => [...$existing, ...$rows]])->save();

        return count($rows);
    }
}
