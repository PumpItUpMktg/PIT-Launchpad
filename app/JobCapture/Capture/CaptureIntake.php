<?php

namespace App\JobCapture\Capture;

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\Integrations\Census\Geocoder;
use App\JobCapture\Photos\JobPhotoStore;
use App\Jobs\EnhanceJob;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;
use App\Models\TechDevice;

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
    public function __construct(
        private readonly JobPhotoStore $photos,
        private readonly Geocoder $geocoder,
    ) {}

    public function capture(TechDevice $device, CaptureData $data): Job
    {
        // A PAST job: the typed address IS the job's location — the phone is wherever the tech happens to
        // be now, so its fix is ignored. Geocoded to the true point, which then jitters and resolves to
        // city/county exactly like a live capture; the photos get the jittered point, never the address.
        // An address that will not geocode still lands the job — with the address kept for the office and
        // no point, so it defers to review like a walk-in instead of being lost from the phone's queue.
        $address = trim((string) $data->address);
        $lat = $data->lat;
        $lng = $data->lng;
        if ($address !== '') {
            $point = $this->geocoder->geocode($address);
            $lat = $point?->lat;
            $lng = $point?->lng;
        }

        $job = new Job([
            'site_id' => $device->site_id,
            'source' => JobSource::Manual,
            'status' => JobStatus::Captured,
            'tech_id' => $device->id,
            'client_name_full' => $data->clientNameFull,
            'client_name_display' => $data->clientNameDisplay,
            'raw_description' => $data->rawDescription,
            'source_description' => $data->rawDescription,   // seed the editable source from the raw input
            'address_true' => $address !== '' ? $address : null,
            'lat_true' => $lat,
            'lng_true' => $lng,
            'performed_at' => $data->performedAt,
            'primary_photo_index' => $data->primaryPhotoIndex,
        ]);
        $job->save();

        $this->storePhotos($job, $device, $data);
        $this->snapshotJobTypes($job, $data);

        // A point present (device fix, or the geocoded address) → resolve geography off the request; a
        // walk-in without coordinates, or an address that would not geocode, defers to review.
        if ($lat !== null && $lng !== null) {
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

        $job->forceFill(['photos' => $this->photos->store($site, $job, $data->photos, Job::MAX_PHOTOS)])->save();
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
