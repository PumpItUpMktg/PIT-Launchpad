<?php

use App\Models\Content;
use App\Models\Site;
use App\Operate\QueueHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function insertFailedJob(string $displayName, string $exception = 'Illuminate\\Queue\\MaxAttemptsExceededException: attempted too many times'): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => (string) json_encode(['displayName' => $displayName, 'data' => ['command' => '']]),
        'exception' => $exception,
        'failed_at' => now(),
    ]);
}

test('a benign WarmLiveMetrics failure does not trip the stalled banner or show in failures', function () {
    insertFailedJob('App\\Jobs\\WarmLiveMetrics');

    $health = app(QueueHealth::class);

    expect($health->snapshot()['failed'])->toBe(0)      // excluded from the count
        ->and($health->snapshot()['stalled'])->toBeFalse() // so no phantom stall from an interrupted warm
        ->and($health->failures())->toBe([]);           // and never listed (no "regenerate the page" nonsense)
});

test('a real (non-benign) failure still counts, stalls, and shows', function () {
    insertFailedJob('App\\Jobs\\PublishContent');

    $health = app(QueueHealth::class);

    expect($health->snapshot()['failed'])->toBe(1)
        ->and($health->snapshot()['stalled'])->toBeTrue()
        ->and($health->failures())->toHaveCount(1);
});

test('pruneBenignFailures deletes only the benign warm rows', function () {
    insertFailedJob('App\\Jobs\\WarmLiveMetrics');
    insertFailedJob('App\\Jobs\\WarmLiveMetrics');
    insertFailedJob('App\\Jobs\\PublishContent');

    expect(app(QueueHealth::class)->pruneBenignFailures())->toBe(2)
        ->and(DB::table('failed_jobs')->count())->toBe(1); // the real failure remains
});

test('QueueHealth flags a stalled worker when a job has sat past the threshold', function () {
    // A pending job that became available 10 minutes ago — past the 5-minute stall threshold.
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => time() - 600, 'created_at' => time() - 600,
    ]);

    $snap = app(QueueHealth::class)->snapshot(5);

    expect($snap['pending'])->toBe(1)
        ->and($snap['oldest_minutes'])->toBeGreaterThanOrEqual(9)
        ->and($snap['stalled'])->toBeTrue();
});

test('QueueHealth is not stalled with an empty queue, and a fresh backlog is not yet stalled', function () {
    expect(app(QueueHealth::class)->snapshot()['stalled'])->toBeFalse();

    // A job queued just now — the worker may simply be mid-drain; not stalled until it ages past the floor.
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => time(), 'created_at' => time(),
    ]);

    expect(app(QueueHealth::class)->snapshot(5)['stalled'])->toBeFalse();
});

test('an ageing backlog with a job IN FLIGHT is draining, not stalled (no false alarm)', function () {
    // 3 queued, the oldest available 10m ago — but a worker is holding one right now (reserved just now).
    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time() - 5, 'available_at' => time() - 600, 'created_at' => time() - 600],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 400, 'created_at' => time() - 400],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 200, 'created_at' => time() - 200],
    ]);

    $snap = app(QueueHealth::class)->snapshot(5);

    expect($snap['pending'])->toBe(3)
        ->and($snap['processing'])->toBe(1)     // a worker is actively chewing
        ->and($snap['draining'])->toBeTrue()
        ->and($snap['worker_down'])->toBeFalse()
        ->and($snap['stalled'])->toBeFalse();   // NOT an alarm — it's just clearing one at a time
});

test('an ageing backlog with NOTHING in flight is worker-down (a real stall)', function () {
    // Same-age backlog, but no job is reserved — nobody is working it.
    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 600, 'created_at' => time() - 600],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 400, 'created_at' => time() - 400],
    ]);

    $snap = app(QueueHealth::class)->snapshot(5);

    expect($snap['processing'])->toBe(0)
        ->and($snap['draining'])->toBeFalse()
        ->and($snap['worker_down'])->toBeTrue()
        ->and($snap['stalled'])->toBeTrue();
});

test('a STALE reservation (worker died mid-job) counts as down, not draining', function () {
    // reserved_at set but 10m old — past the window → the holding worker is gone, backlog is ageing.
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time() - 600, 'available_at' => time() - 600, 'created_at' => time() - 600,
    ]);

    $snap = app(QueueHealth::class)->snapshot(5);

    expect($snap['processing'])->toBe(0)        // the stale reservation is not "recent"
        ->and($snap['worker_down'])->toBeTrue()
        ->and($snap['stalled'])->toBeTrue();
});

test('QueueHealth flags any failed job as stalled', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
    ]);

    expect(app(QueueHealth::class)->snapshot()['failed'])->toBe(1)
        ->and(app(QueueHealth::class)->snapshot()['stalled'])->toBeTrue();
});

function qhFailedJob(string $job, string $exception): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => $job]),
        'exception' => $exception, 'failed_at' => now(),
    ]);
}

test('QueueHealth::failures groups the failed jobs by cause (job class + reason), most frequent first', function () {
    qhFailedJob('App\\Jobs\\PublishContent', "RuntimeException: WP 401 unauthorized\n#0 /app/...");
    qhFailedJob('App\\Jobs\\PublishContent', "RuntimeException: WP 401 unauthorized\n#0 /app/...");
    qhFailedJob('App\\Jobs\\RenderImage', "Fal\\FalException: 402 out of credits\n#0 /app/...");

    $failures = app(QueueHealth::class)->failures();

    expect($failures)->toHaveCount(2)
        // Most frequent first: the 2× PublishContent 401 group leads.
        ->and($failures[0]['job'])->toBe('PublishContent')            // basename only, not the FQCN
        ->and($failures[0]['count'])->toBe(2)
        ->and($failures[0]['reason'])->toContain('WP 401 unauthorized') // first exception line, no stack trace
        ->and($failures[1]['job'])->toBe('RenderImage')
        ->and($failures[1]['count'])->toBe(1);
});

function qhFailedJobFor(string $job, string $exception, string $contentId): void
{
    // A realistic failed_jobs payload: data.command is the serialized job carrying its contentId.
    $command = 'O:20:"'.$job.'":1:{s:9:"contentId";s:'.strlen($contentId).':"'.$contentId.'";}';
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => $job, 'data' => ['commandName' => $job, 'command' => $command]]),
        'exception' => $exception, 'failed_at' => now(),
    ]);
}

test('failures names the affected page and de-jargons an interrupted (MaxAttempts) generate job', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create(['site_id' => $site->id, 'title' => 'Bristol, PA']);
    qhFailedJobFor(
        'App\\Jobs\\GeneratePage',
        'Illuminate\\Queue\\MaxAttemptsExceededException: App\\Jobs\\GeneratePage has been attempted too many times.',
        (string) $page->id,
    );

    $failures = app(QueueHealth::class)->failures();

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['job'])->toBe('GeneratePage')
        ->and($failures[0]['pages'])->toBe(['Bristol, PA'])          // WHICH page
        ->and($failures[0]['reason'])->toContain('interrupted')      // friendly cause
        ->and($failures[0]['reason'])->toContain('regenerate')
        ->and($failures[0]['reason'])->not->toContain('MaxAttemptsExceeded'); // no raw framework jargon
});

test('QueueHealth::clearFailed empties the failed_jobs table and returns the count', function () {
    qhFailedJob('App\\Jobs\\PublishContent', 'boom');
    qhFailedJob('App\\Jobs\\RenderImage', 'boom');

    expect(app(QueueHealth::class)->clearFailed())->toBe(2)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(app(QueueHealth::class)->snapshot()['stalled'])->toBeFalse(); // banner clears with them
});
