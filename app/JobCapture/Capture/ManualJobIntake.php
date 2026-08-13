<?php

namespace App\JobCapture\Capture;

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\Integrations\Census\Geocoder;
use App\Jobs\EnhanceJob;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;
use App\Models\Site;
use App\Publishing\TenantStorage;
use Illuminate\Support\Str;

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
        private readonly TenantStorage $storage,
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
            'client_name_display' => $this->displayName($data->clientName),
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

    /** The pushed "First L." display name, derived from the internal-only full name (privacy contract §4). */
    private function displayName(string $full): ?string
    {
        $full = trim($full);
        if ($full === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $full) ?: [$full];
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = (string) end($parts);

        return $parts[0].' '.mb_strtoupper(mb_substr($last, 0, 1)).'.';
    }

    /**
     * @param  list<array{bytes: string, filename?: string}>  $photos
     */
    private function storePhotos(Site $site, Job $job, array $photos): void
    {
        if ($photos === []) {
            return;
        }

        $stored = [];
        foreach (array_slice($photos, 0, 3) as $i => $photo) {
            $filename = $photo['filename'] ?? ($i + 1).'.jpg';
            $stored[] = [
                'r2_key' => $this->storage->putForJob($site, $job->id, $filename, $photo['bytes']),
                'hash' => hash('sha256', $photo['bytes']),
            ];
        }

        $job->forceFill(['photos' => $stored])->save();
    }

    /**
     * @param  list<array{label: string, slug?: string, job_type_id?: string|null}>  $jobTypes
     */
    private function snapshotJobTypes(Job $job, array $jobTypes): void
    {
        foreach (array_slice($jobTypes, 0, Job::MAX_JOB_TYPES) as $type) {
            $label = trim($type['label']);
            if ($label === '') {
                continue;
            }

            $slug = trim((string) ($type['slug'] ?? ''));
            $job->jobTypes()->create([
                'job_type_id' => $type['job_type_id'] ?? null,
                'label' => $label,
                'slug' => $slug !== '' ? $slug : Str::slug($label),
            ]);
        }
    }
}
