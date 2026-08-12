<?php

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\JobCapture\Capture\CaptureData;
use App\JobCapture\Capture\CaptureIntake;
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
        photos: [['bytes' => 'IMG1', 'filename' => '1.jpg'], ['bytes' => 'IMG2']],
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

test('it caps photos and job types at three', function () {
    Storage::fake('r2');
    Queue::fake();
    $device = TechDevice::factory()->create();

    $job = app(CaptureIntake::class)->capture($device, new CaptureData(
        rawDescription: 'many',
        photos: array_map(fn (int $i): array => ['bytes' => "IMG{$i}"], range(1, 5)),
        jobTypes: array_map(fn (int $i): array => ['label' => "Type {$i}", 'slug' => "type-{$i}"], range(1, 5)),
    ));

    expect($job->photos)->toHaveCount(3)
        ->and($job->jobTypes()->count())->toBe(3);
});
