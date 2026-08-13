<?php

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\GeocodeResult;
use App\JobCapture\Capture\CouldNotPlaceJobException;
use App\JobCapture\Capture\ManualJobData;
use App\JobCapture\Capture\ManualJobIntake;
use App\Jobs\EnhanceJob;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;
use App\Models\Site;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** Bind a geocoder that resolves any address to a fixed point (the default test geocoder returns null). */
function bindGeocoderAt(float $lat, float $lng): void
{
    app()->instance(Geocoder::class, new class($lat, $lng) implements Geocoder
    {
        public function __construct(private float $lat, private float $lng) {}

        public function geocode(string $address): ?GeocodeResult
        {
            return new GeocodeResult($this->lat, $this->lng, $address);
        }
    });
}

it('creates a manual captured job from an operator entry, geocoded and dispatched down the pipeline', function () {
    Queue::fake();
    bindGeocoderAt(40.55, -74.40);
    $site = Site::factory()->create();

    $job = app(ManualJobIntake::class)->intake($site, new ManualJobData(
        clientName: 'Jane Homeowner',
        address: '12 Main St, Somerville NJ',
        performedAt: '2025-06-01',
        rawDescription: 'Replaced a failed sump pump in the basement.',
        jobTypes: [['label' => 'Sump Pump Replacement']],
    ));

    expect($job->source)->toBe(JobSource::Manual)
        ->and($job->status)->toBe(JobStatus::Captured)
        ->and($job->tech_id)->toBeNull()
        ->and($job->client_name_display)->toBe('Jane H.')          // pushed display, derived
        ->and($job->client_name_full)->toBe('Jane Homeowner')       // internal only
        ->and($job->address_true)->toBe('12 Main St, Somerville NJ')
        ->and((float) $job->lat_true)->toBe(40.55)
        ->and((float) $job->lng_true)->toBe(-74.40)
        ->and($job->performed_at->toDateString())->toBe('2025-06-01')
        ->and($job->source_description)->toBe('Replaced a failed sump pump in the basement.')
        ->and($job->jobTypes()->pluck('label')->all())->toBe(['Sump Pump Replacement']);

    Queue::assertPushed(ResolveJobGeography::class, fn (ResolveJobGeography $j): bool => $j->jobId === $job->id);
    Queue::assertPushed(EnhanceJob::class, fn (EnhanceJob $j): bool => $j->jobId === $job->id);
});

it('refuses to create a job when the address cannot be geocoded', function () {
    Queue::fake();
    // The default test Geocoder returns null — no point, no job.
    $site = Site::factory()->create();

    expect(fn () => app(ManualJobIntake::class)->intake($site, new ManualJobData(
        clientName: 'Jane Homeowner',
        address: 'nowhere at all',
    )))->toThrow(CouldNotPlaceJobException::class);

    expect(Job::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('stores uploaded photos under the per-job prefix', function () {
    Queue::fake();
    Storage::fake(TenantStorage::DISK);
    bindGeocoderAt(40.55, -74.40);
    $site = Site::factory()->create();

    $job = app(ManualJobIntake::class)->intake($site, new ManualJobData(
        clientName: 'Jane Homeowner',
        address: '12 Main St',
        photos: [['bytes' => 'FAKEJPEGBYTES', 'filename' => 'before.jpg']],
    ));

    expect($job->photos)->toHaveCount(1)
        ->and($job->photos[0]['r2_key'])->toContain($job->id);
    Storage::disk(TenantStorage::DISK)->assertExists($job->photos[0]['r2_key']);
});

it('does not enhance when no description is given (nothing to draft from)', function () {
    Queue::fake();
    bindGeocoderAt(40.55, -74.40);
    $site = Site::factory()->create();

    app(ManualJobIntake::class)->intake($site, new ManualJobData(
        clientName: 'Jane Homeowner',
        address: '12 Main St',
    ));

    Queue::assertPushed(ResolveJobGeography::class); // geography always runs (we have coords)
    Queue::assertNotPushed(EnhanceJob::class);
});
