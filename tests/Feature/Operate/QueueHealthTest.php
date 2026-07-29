<?php

use App\Operate\QueueHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

test('QueueHealth::clearFailed empties the failed_jobs table and returns the count', function () {
    qhFailedJob('App\\Jobs\\PublishContent', 'boom');
    qhFailedJob('App\\Jobs\\RenderImage', 'boom');

    expect(app(QueueHealth::class)->clearFailed())->toBe(2)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(app(QueueHealth::class)->snapshot()['stalled'])->toBeFalse(); // banner clears with them
});
