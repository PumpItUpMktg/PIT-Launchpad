<?php

namespace App\JobCapture\Capture;

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\Jobs\EnhanceJob;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;
use App\Models\TechDevice;
use App\Publishing\TenantStorage;

/**
 * Turns a tech's {@see CaptureData} submission into a persisted {@see Job} (§5). Creates the job as a
 * `manual`, `captured` record owned by the device's tenant and tech, seeds the operator-editable
 * `source_description` from the immutable `raw_description`, stores each photo under the per-job R2 prefix
 * (capped at 3), snapshots the applied job types (capped at {@see Job::MAX_JOB_TYPES}), and — when the
 * device supplied GPS coordinates — dispatches {@see ResolveJobGeography} to resolve city/county + jitter
 * off the request. A walk-in with no coordinates is left for the operator to place at review.
 */
final class CaptureIntake
{
    public function __construct(private readonly TenantStorage $storage) {}

    public function capture(TechDevice $device, CaptureData $data): Job
    {
        $job = new Job([
            'site_id' => $device->site_id,
            'source' => JobSource::Manual,
            'status' => JobStatus::Captured,
            'tech_id' => $device->id,
            'client_name_full' => $data->clientNameFull,
            'client_name_display' => $data->clientNameDisplay,
            'raw_description' => $data->rawDescription,
            'source_description' => $data->rawDescription,   // seed the editable source from the raw input
            'lat_true' => $data->lat,
            'lng_true' => $data->lng,
            'primary_photo_index' => $data->primaryPhotoIndex,
        ]);
        $job->save();

        $this->storePhotos($job, $device, $data);
        $this->snapshotJobTypes($job, $data);

        // GPS present → resolve geography off the request; a walk-in without coordinates defers to review.
        if ($data->lat !== null && $data->lng !== null) {
            ResolveJobGeography::dispatch($job->id);
        }

        // Enhancement (§7) fires after submit, off the request — never blocking the tech, and re-runnable
        // by the operator. Only when there is something to enhance.
        if (trim((string) $job->source_description) !== '') {
            EnhanceJob::dispatch($job->id);
        }

        return $job;
    }

    private function storePhotos(Job $job, TechDevice $device, CaptureData $data): void
    {
        $site = $device->site;
        if ($site === null || $data->photos === []) {
            return;
        }

        $photos = [];
        foreach (array_slice($data->photos, 0, 3) as $i => $photo) {
            $filename = $photo['filename'] ?? ($i + 1).'.jpg';
            $photos[] = [
                'r2_key' => $this->storage->putForJob($site, $job->id, $filename, $photo['bytes']),
                'hash' => hash('sha256', $photo['bytes']),
            ];
        }

        $job->forceFill(['photos' => $photos])->save();
    }

    private function snapshotJobTypes(Job $job, CaptureData $data): void
    {
        foreach (array_slice($data->jobTypes, 0, Job::MAX_JOB_TYPES) as $type) {
            $job->jobTypes()->create([
                'job_type_id' => $type['job_type_id'] ?? null,
                'label' => $type['label'],
                'slug' => $type['slug'],
            ]);
        }
    }
}
