<?php

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\MockCensusGeocoder;
use App\JobCapture\Capture\CaptureData;
use App\JobCapture\Capture\CaptureIntake;
use App\Jobs\EnhanceJob;
use App\Jobs\ResolveJobGeography;
use App\Models\TechDevice;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('it captures a manual job — photos under the per-job prefix, source seeded from raw, geography dispatched for a GPS point', function () {
    Storage::fake('r2');
    Queue::fake();
    $device = TechDevice::factory()->create();

    $job = app(CaptureIntake::class)->capture($device, new CaptureData(
        clientNameFull: 'Jane Homeowner',
        clientNameDisplay: 'Jane H.',
        rawDescription: 'Replaced a failed sump pump and cleared the pit.',
        lat: 40.66, lng: -74.65,
        photos: [['bytes' => tinyJpeg(), 'filename' => '1.jpg'], ['bytes' => tinyJpeg()]],
        jobTypes: [['label' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair']],
    ));

    expect($job->source)->toBe(JobSource::Manual)
        ->and($job->status)->toBe(JobStatus::Captured)
        ->and($job->tech_id)->toBe($device->id)
        ->and($job->site_id)->toBe($device->site_id)
        ->and($job->source_description)->toBe($job->raw_description)
        ->and($job->photos)->toHaveCount(2)
        ->and($job->jobTypes()->count())->toBe(1)
        ->and($job->photos[0]['r2_key'])->toContain("sites/{$device->site_id}/jobs/{$job->id}/");

    Storage::disk('r2')->assertExists($job->photos[0]['r2_key']);
    Queue::assertPushed(ResolveJobGeography::class, fn (ResolveJobGeography $j): bool => $j->jobId === $job->id);
});

test('a walk-in without coordinates does not dispatch geography', function () {
    Storage::fake('r2');
    Queue::fake();
    $device = TechDevice::factory()->create();

    $job = app(CaptureIntake::class)->capture($device, new CaptureData(
        clientNameDisplay: 'Walk In',
        rawDescription: 'Phone-dispatched, no GPS.',
    ));

    expect($job->lat_true)->toBeNull();
    Queue::assertNotPushed(ResolveJobGeography::class);
});

test('capturing a job with a description dispatches enhancement (§7)', function () {
    Storage::fake('r2');
    Queue::fake();

    app(CaptureIntake::class)->capture(TechDevice::factory()->create(), new CaptureData(
        rawDescription: 'Fixed a failed sump pump.',
    ));

    Queue::assertPushed(EnhanceJob::class);
});

test('it caps photos and job types at three', function () {
    Storage::fake('r2');
    Queue::fake();
    $device = TechDevice::factory()->create();

    $job = app(CaptureIntake::class)->capture($device, new CaptureData(
        rawDescription: 'many',
        photos: array_map(fn (int $i): array => ['bytes' => tinyJpeg()], range(1, 5)),
        jobTypes: array_map(fn (int $i): array => ['label' => "Type {$i}", 'slug' => "type-{$i}"], range(1, 5)),
    ));

    expect($job->photos)->toHaveCount(3)
        ->and($job->jobTypes()->count())->toBe(3);
});

test('a PAST job with a typed address is placed at the address — not the phone — with the date the work was done, and the photos stay clean of the street', function () {
    Storage::fake('r2');
    Queue::fake();
    app()->instance(Geocoder::class, new MockCensusGeocoder(40.3101, -75.1299)); // Doylestown, PA
    $device = TechDevice::factory()->create();

    $job = app(CaptureIntake::class)->capture($device, new CaptureData(
        clientNameDisplay: 'Sam M.',
        rawDescription: 'Replaced a failed sump pump last spring.',
        lat: 40.66, lng: -74.65,                          // the phone is at the office today — ignored
        photos: [['bytes' => tinyJpeg(), 'filename' => '1.jpg']],
        address: '12 Main St, Doylestown, PA 18901',
        performedAt: '2026-04-14',
    ));

    expect((float) $job->lat_true)->toBe(40.3101)
        ->and((float) $job->lng_true)->toBe(-75.1299)
        ->and($job->address_true)->toBe('12 Main St, Doylestown, PA 18901')
        ->and($job->performed_at?->toDateString())->toBe('2026-04-14')
        ->and($job->photos)->toHaveCount(1);
    Queue::assertPushed(ResolveJobGeography::class, fn (ResolveJobGeography $j): bool => $j->jobId === $job->id);
});

test('a PAST job whose address will not geocode still lands — address kept for the office, no point, deferred to review', function () {
    Storage::fake('r2');
    Queue::fake();
    app()->instance(Geocoder::class, new MockCensusGeocoder(unmatchable: ['nowhere at all']));
    $device = TechDevice::factory()->create();

    $job = app(CaptureIntake::class)->capture($device, new CaptureData(
        rawDescription: 'Old job.', lat: 40.66, lng: -74.65, address: 'nowhere at all', performedAt: '2026-04-14',
    ));

    expect($job->lat_true)->toBeNull()
        ->and($job->lng_true)->toBeNull()
        ->and($job->address_true)->toBe('nowhere at all');
    Queue::assertNotPushed(ResolveJobGeography::class);
});
