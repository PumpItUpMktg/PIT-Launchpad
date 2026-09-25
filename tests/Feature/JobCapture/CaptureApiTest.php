<?php

use App\Integrations\Census\Geocoder;
use App\Integrations\Census\MockCensusGeocoder;
use App\JobCapture\Auth\DeviceAuthenticator;
use App\JobCapture\Capture\CaptureData;
use App\JobCapture\Capture\CaptureIntake;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;
use App\Models\TechDevice;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** Log a device in through the real issue→redeem flow and return its bearer token. */
function techToken(TechDevice $device): string
{
    $auth = app(DeviceAuthenticator::class);
    $code = $auth->issueLoginCode($device);

    return (string) $auth->redeemLoginCode($device->fresh(), $code);
}

test('request-code then redeem yields a device token', function () {
    $device = TechDevice::factory()->create();

    $code = $this->postJson('/capture/api/auth/request-code', ['device' => $device->id])
        ->assertOk()->json('code');

    $this->postJson('/capture/api/auth/redeem', ['device' => $device->id, 'code' => $code])
        ->assertOk()->assertJsonStructure(['token', 'tech']);
});

test('redeem with a wrong code is rejected', function () {
    $device = TechDevice::factory()->create();

    $code = $this->postJson('/capture/api/auth/request-code', ['device' => $device->id])->json('code');
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->postJson('/capture/api/auth/redeem', ['device' => $device->id, 'code' => $wrong])
        ->assertUnauthorized();
});

test('submitting a captured job requires a device token', function () {
    $this->postJson('/capture/api/jobs', ['raw_description' => 'x'])->assertUnauthorized();
});

test('an authenticated tech captures a job through the API', function () {
    Storage::fake('r2');
    Queue::fake();
    $device = TechDevice::factory()->create();

    $response = $this->withToken(techToken($device))->postJson('/capture/api/jobs', [
        'client_name_display' => 'Jane H.',
        'raw_description' => 'Replaced a sump pump.',
        'lat' => 40.66, 'lng' => -74.65,
        'photos' => [['data' => base64_encode(tinyJpeg()), 'filename' => '1.jpg']],
        'job_types' => [['label' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair']],
    ])->assertCreated();

    $job = Job::withoutGlobalScopes()->find($response->json('id'));

    expect($job)->not->toBeNull()
        ->and($job->tech_id)->toBe($device->id)
        ->and($job->site_id)->toBe($device->site_id)
        ->and($job->photos)->toHaveCount(1);

    Queue::assertPushed(ResolveJobGeography::class);
});

test('the job list returns this tech\'s captured jobs', function () {
    Storage::fake('r2');
    Queue::fake();
    $device = TechDevice::factory()->create();
    $token = techToken($device);

    app(CaptureIntake::class)->capture($device, new CaptureData(
        clientNameDisplay: 'Jane H.',
        rawDescription: 'A captured job.',
    ));

    $this->withToken($token)->getJson('/capture/api/jobs')
        ->assertOk()
        ->assertJsonCount(1, 'jobs')
        ->assertJsonPath('jobs.0.client', 'Jane H.');
});

test('the API accepts a past job with an address and date, reports whether it was placed, and refuses a future date', function () {
    Storage::fake('r2');
    Queue::fake();
    app()->instance(Geocoder::class, new MockCensusGeocoder(40.3101, -75.1299));
    $device = TechDevice::factory()->create();
    $token = techToken($device);

    $response = $this->withToken($token)->postJson('/capture/api/jobs', [
        'client_name_display' => 'Sam M.',
        'raw_description' => 'Replaced a sump pump last spring.',
        'lat' => 40.66, 'lng' => -74.65,
        'address' => '12 Main St, Doylestown, PA 18901',
        'performed_at' => '2026-04-14',
        'photos' => [['data' => base64_encode(tinyJpeg()), 'filename' => '1.jpg']],
    ])->assertCreated()->assertJson(['placed' => true]);
    $job = Job::withoutGlobalScopes()->find($response->json('id'));
    expect((float) $job->lat_true)->toBe(40.3101)
        ->and($job->performed_at?->toDateString())->toBe('2026-04-14');

    $this->withToken($token)->postJson('/capture/api/jobs', [
        'raw_description' => 'x', 'address' => '12 Main St', 'performed_at' => now()->addDay()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['performed_at']);

    // No address, no fix → not placed (the office sets the location in review).
    $this->withToken($token)->postJson('/capture/api/jobs', ['raw_description' => 'walk-in'])
        ->assertCreated()->assertJson(['placed' => false]);
});
