<?php

namespace App\JobCapture\Capture;

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\Integrations\Census\Geocoder;
use App\JobCapture\Photos\JobPhotoStore;
use App\JobCapture\Types\JobTypeVocabulary;
use App\Jobs\EnhanceJob;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;
use App\Models\Site;

/**
 * Operator-side counterpart to {@see CaptureIntake}: turns a {@see ManualJobData} entry into a persisted
 * {@see Job} for a PREVIOUS job an admin is backfilling. It mirrors the capture path — a `manual`,
 * `captured` record with the operator-editable `source_description` seeded from the raw input, photos + job
 * types snapshotted, then geography + enhancement dispatched off the request — with two differences: there's
 * no device/tech, and there's no GPS, so the typed address is GEOCODED to the true point ({@see Geocoder})
 * that {@see GeographyResolver} then turns into city/county + the privacy jitter. A `performed_at` date
 * carries the real job date.
 */
final class ManualJobIntake
{
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly JobPhotoStore $photos,
        private readonly JobTypeVocabulary $vocabulary,
    ) {}

    /**
     * @throws CouldNotPlaceJobException when the address can't be geocoded to a point
     */
    public function intake(Site $site, ManualJobData $data): Job
    {
        $address = trim($data->address);
        $point = $address !== '' ? $this->geocoder->geocode($address) : null;
        if ($point === null) {
            throw new CouldNotPlaceJobException('Couldn’t find that address — check it and try again.');
        }

        $raw = $data->rawDescription !== null && trim($data->rawDescription) !== '' ? trim($data->rawDescription) : null;

        $job = new Job([
            'site_id' => $site->id,
            'source' => JobSource::Manual,
            'status' => JobStatus::Captured,
            'tech_id' => null,
            'client_name_full' => trim($data->clientName) !== '' ? trim($data->clientName) : null,
            'client_name_display' => ClientDisplayName::from($data->clientName),
            'address_true' => $address,
            'lat_true' => $point->lat,
            'lng_true' => $point->lng,
            'raw_description' => $raw,
            'source_description' => $raw, // seed the editable AI source from the raw input
            'performed_at' => $data->performedAt,
            'primary_photo_index' => 0,
        ]);
        $job->save();

        $this->storePhotos($site, $job, $data->photos);
        $this->snapshotJobTypes($job, $data->jobTypes);

        // Coordinates always exist here (geocoded), so geography resolves exactly like a GPS capture.
        ResolveJobGeography::dispatch($job->id);

        if ($raw !== null) {
            EnhanceJob::dispatch($job->id);
        }

        return $job;
    }

    /**
     * @param  list<array{bytes: string, filename?: string}>  $photos
     */
    private function storePhotos(Site $site, Job $job, array $photos): void
    {
        if ($photos === []) {
            return;
        }

        $job->forceFill(['photos' => $this->photos->store($site, $job, $photos, Job::MAX_PHOTOS)])->save();
    }

    /**
     * Snapshot the applied types, linked to the vocabulary where a label matches (so a CSV / form label
     * such as "Sump Pump Replacement" lands on the catalog's row, not a free-floating twin).
     *
     * @param  list<array{label: string, slug?: string, job_type_id?: string|null}>  $jobTypes
     */
    private function snapshotJobTypes(Job $job, array $jobTypes): void
    {
        foreach ($this->vocabulary->resolve((string) $job->site_id, $jobTypes) as $type) {
            $job->jobTypes()->create($type);
        }
    }
}
