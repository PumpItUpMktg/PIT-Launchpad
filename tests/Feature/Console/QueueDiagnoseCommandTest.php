<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function failedJob(string $jobClass, string $exception, ?string $failedAt = null): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => $jobClass, 'data' => []]),
        'exception' => $exception, 'failed_at' => $failedAt ?? now(),
    ]);
}

it('groups failed jobs by class + exception, most frequent first', function () {
    // Three fal-credit failures + one WP 401 → the fal cause should lead.
    failedJob('App\\Jobs\\RenderImage', "RuntimeException: fal returned HTTP 402 — insufficient credits\n#0 /app/...");
    failedJob('App\\Jobs\\RenderImage', "RuntimeException: fal returned HTTP 402 — insufficient credits\n#0 /app/...");
    failedJob('App\\Jobs\\RenderImage', "RuntimeException: fal returned HTTP 402 — insufficient credits\n#0 /app/...");
    failedJob('App\\Jobs\\PublishContent', "WordpressException: HTTP 401 — invalid app password\n#0 /app/...");

    Artisan::call('launchpad:queue-diagnose');
    $out = Artisan::output();

    expect($out)->toContain('Failed: 4 job(s).')
        ->toContain('3×')
        ->toContain('RenderImage')
        ->toContain('insufficient credits')
        ->toContain('PublishContent');
});

it('flags a worker that is DOWN when jobs are queued but none have failed', function () {
    DB::table('jobs')->insert([
        'queue' => 'default', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->subMinutes(20)->timestamp, 'created_at' => now()->subMinutes(20)->timestamp,
        'payload' => '{"displayName":"App\\\\Jobs\\\\PublishContent"}',
    ]);

    Artisan::call('launchpad:queue-diagnose');
    $out = Artisan::output();

    expect($out)->toContain('Pending: 1 job(s)')
        ->toContain('WORKER being down');
});

it('reports a clean queue', function () {
    Artisan::call('launchpad:queue-diagnose');

    expect(Artisan::output())->toContain('Queue is clean');
});

it('--flush deletes the failed_jobs backlog after reporting', function () {
    failedJob('App\\Jobs\\GeneratePost', "PDOException: foreign key violation contents_silo_id_foreign\n#0 ...");
    failedJob('App\\Jobs\\PopulateBlog', "TimeoutExceededException: timed out\n#0 ...");

    Artisan::call('launchpad:queue-diagnose', ['--flush' => true]);
    $out = Artisan::output();

    expect($out)->toContain('Failures by cause')      // still reports what they were
        ->toContain('Flushed 2 failed job(s)')
        ->and(DB::table('failed_jobs')->count())->toBe(0); // …then clears them
});
