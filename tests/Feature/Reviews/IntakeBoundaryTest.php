<?php

use App\Models\Job;
use App\Models\Site;
use App\Reviews\Intake\CompletedJob;
use App\Reviews\Intake\Contracts\JobSource;
use App\Reviews\Intake\ManualJobSource;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;

test('the completed-job payload renders the name only as "First L."', function (): void {
    $job = new CompletedJob(
        siteId: 'site-1', externalRef: null,
        customerFirstName: 'John', customerLastInitial: 'D',
        customerEmail: 'john@example.com', customerPhone: null,
        serviceAddress: '123 Main St, Trooper, PA', locationId: 'loc-1',
        serviceIds: ['svc-1'], completedAt: Carbon::parse('2026-08-01'),
    );

    expect($job->displayName())->toBe('John D.')
        ->and($job->serviceAddress)->toBe('123 Main St, Trooper, PA'); // stored for audit, never rendered
});

test('a one-word name gets no last initial', function (): void {
    $job = new CompletedJob(
        siteId: 's', externalRef: null, customerFirstName: 'Cher', customerLastInitial: '',
        customerEmail: 'c@x.com', customerPhone: null, serviceAddress: '', locationId: null,
        serviceIds: [], completedAt: Carbon::now(),
    );

    expect($job->displayName())->toBe('Cher');
});

test('the default JobSource binding is the manual driver', function (): void {
    expect(app(JobSource::class))->toBeInstanceOf(ManualJobSource::class);
});

test('the manual driver is push, not poll — pending() is empty', function (): void {
    expect(iterator_to_array((function () {
        yield from (new ManualJobSource)->pending();
    })()))->toBe([]);
});

test('fromDetails maps pasted operator input into the payload', function (): void {
    $payload = (new ManualJobSource)->fromDetails([
        'site_id' => 'site-9',
        'customer_name' => 'Maria Gonzalez',
        'customer_email' => 'maria@example.com',
        'customer_phone' => '610-555-0100',
        'service_address' => '9 Elm Ave, Boyertown, PA 19512',
        'location_id' => 'loc-7',
        'service_ids' => ['svc-a', 'svc-b'],
        'completed_at' => '2026-07-15',
    ]);

    expect($payload->siteId)->toBe('site-9')
        ->and($payload->displayName())->toBe('Maria G.')
        ->and($payload->customerEmail)->toBe('maria@example.com')
        ->and($payload->customerPhone)->toBe('610-555-0100')
        ->and($payload->locationId)->toBe('loc-7')
        ->and($payload->serviceIds)->toBe(['svc-a', 'svc-b'])
        ->and($payload->completedAt->toDateString())->toBe('2026-07-15')
        ->and($payload->externalRef)->toBeNull();
});

test('fromDetails leaves a blank location as needs-location (null), never guessed', function (): void {
    $payload = (new ManualJobSource)->fromDetails([
        'site_id' => 's', 'customer_name' => 'Sam Lee', 'customer_email' => 's@x.com',
    ]);

    expect($payload->locationId)->toBeNull()
        ->and($payload->serviceIds)->toBe([]);
});

test('fromJob maps an existing Job Capture record — the only place a Job shape enters the module', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create([
        'client_name_full' => 'Robert Smith',
        'address_true' => '500 W 2nd St, Trooper, PA',
        'performed_at' => '2026-06-20',
    ]);

    $payload = (new ManualJobSource)->fromJob($job, 'rob@example.com', null, 'loc-3', ['svc-x']);

    expect($payload->siteId)->toBe((string) $site->id)
        ->and($payload->externalRef)->toBe((string) $job->id)     // the job is the manual review's external ref
        ->and($payload->displayName())->toBe('Robert S.')
        ->and($payload->customerEmail)->toBe('rob@example.com')
        ->and($payload->serviceAddress)->toBe('500 W 2nd St, Trooper, PA')
        ->and($payload->locationId)->toBe('loc-3')
        ->and($payload->completedAt->toDateString())->toBe('2026-06-20');
});
