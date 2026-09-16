<?php

use App\Models\QueueWorker;
use App\Operate\QueueHealth;
use Illuminate\Support\Facades\DB;

function queueJob(string $queue, int $ageSeconds = 0, bool $reserved = false): void
{
    DB::table('jobs')->insert([
        'queue' => $queue, 'payload' => '{"displayName":"App\\\\Jobs\\\\IngestTownRankScans"}', 'attempts' => 0,
        'reserved_at' => $reserved ? time() : null, 'available_at' => time() - $ageSeconds, 'created_at' => time() - $ageSeconds,
    ]);
}

function worker(string $id, string $queues, array $overrides = []): QueueWorker
{
    return QueueWorker::create(array_merge([
        'worker_id' => $id, 'hostname' => strtok($id, '#'), 'pid' => (int) substr($id, strpos($id, '#') + 1), 'connection' => 'database',
        'queues' => $queues, 'started_at' => now()->subHour(), 'last_seen_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    config(['launchpad.town_rank.queue' => 'high', 'launchpad.geo_grid.queue' => 'high', 'launchpad.metrics.queue' => 'default']);
});

it('lists the expected lanes plus any lane holding jobs, with the live worker polling each', function () {
    queueJob('high', 600);
    queueJob('high', 30, reserved: true);
    queueJob('metrics:gsc', 900);
    worker('web#10', 'high,default', ['current_job' => 'App\\Jobs\\IngestTownRankScans', 'current_queue' => 'high', 'current_job_started_at' => now()->subSeconds(40)]);
    worker('web#11', 'default');

    $lanes = collect(app(QueueHealth::class)->lanes())->keyBy('queue');

    expect($lanes->keys()->all())->toBe(['default', 'high', 'metrics:gsc'])
        ->and($lanes['high'])->toMatchArray(['pending' => 2, 'reserved' => 1, 'expected' => true, 'workers' => ['web#10'], 'alive' => true, 'busy' => 'IngestTownRankScans', 'down' => false])
        ->and($lanes['high']['oldest_minutes'])->toBeGreaterThanOrEqual(9)
        ->and($lanes['default'])->toMatchArray(['pending' => 0, 'expected' => true, 'alive' => true, 'busy' => 'IngestTownRankScans', 'down' => false])
        ->and(collect($lanes['default']['workers'])->sort()->values()->all())->toBe(['web#10', 'web#11'])
        // metrics:gsc holds a job and no configured worker polls it: not expected (folded into default), silent.
        ->and($lanes['metrics:gsc'])->toMatchArray(['pending' => 1, 'expected' => false, 'workers' => [], 'alive' => false, 'busy' => null, 'down' => true]);
});

it('calls a lane down from the heartbeats — an ageing backlog with no live listener — and names the silent lanes', function () {
    queueJob('high', 600);
    queueJob('default', 600);
    // The high worker stopped on memory; the default worker went silent 30 minutes ago without stopping.
    worker('web#10', 'high', ['last_seen_at' => now()->subMinutes(41), 'stopped_at' => now()->subMinutes(41), 'stop_reason' => 'memory limit exceeded (128 MB)']);
    worker('web#11', 'default', ['last_seen_at' => now()->subMinutes(30)]);

    $snap = app(QueueHealth::class)->snapshot();

    expect($snap['worker_down'])->toBeTrue()
        ->and($snap['stalled'])->toBeTrue()
        ->and($snap['silent_lanes'])->toBe(['default', 'high']);

    $workers = collect(app(QueueHealth::class)->workers())->keyBy('worker_id');
    expect($workers['web#10']['state'])->toBe('stopped')
        ->and($workers['web#10']['stop_reason'])->toBe('memory limit exceeded (128 MB)')
        ->and($workers['web#11']['state'])->toBe('silent')
        ->and($workers['web#11']['alive'])->toBeFalse();
});

it('keeps a worker live while it is inside a long job even though it writes no loop ticks', function () {
    queueJob('high', 600);
    worker('web#10', 'high', ['last_seen_at' => now()->subMinutes(4), 'current_job' => 'App\\Jobs\\IngestTownRankScans', 'current_queue' => 'high', 'current_job_started_at' => now()->subMinutes(4)]);

    $snap = app(QueueHealth::class)->snapshot();

    expect($snap['worker_down'])->toBeFalse()
        ->and($snap['draining'])->toBeTrue()
        ->and($snap['lanes'][1])->toMatchArray(['queue' => 'high', 'alive' => true, 'busy' => 'IngestTownRankScans', 'down' => false]);

    // Past the busy grace the same row is no longer trusted: the job could not still be running.
    QueueWorker::query()->update(['last_seen_at' => now()->subMinutes(25), 'current_job_started_at' => now()->subMinutes(25)]);
    expect(app(QueueHealth::class)->snapshot()['worker_down'])->toBeTrue();
});

it('falls back to the backlog-only tell before any worker has reported', function () {
    queueJob('default', 600);

    $snap = app(QueueHealth::class)->snapshot();

    expect($snap['worker_down'])->toBeTrue()->and($snap['silent_lanes'])->toBe(['default']);

    // …and a recently reserved job still reads as a slow drain, not a fault.
    DB::table('jobs')->update(['reserved_at' => time()]);
    expect(app(QueueHealth::class)->snapshot()['worker_down'])->toBeFalse();
});

it('derives the expected lanes from config: default, the Town Rank / geo-grid lanes, and the metric lanes', function () {
    config(['launchpad.town_rank.queue' => 'high', 'launchpad.geo_grid.queue' => '', 'launchpad.metrics.queue' => null]);

    expect(app(QueueHealth::class)->expectedLanes())->toBe(['default', 'high', 'metrics:gsc', 'metrics:dataforseo', 'metrics:ga4', 'metrics:index']);
});

it('does not match a worker to a lane whose name it only nearly polls: a space after the comma is a lane of its own', function () {
    // The Cloud process command reads `--queue=high, default`. queue:work splits on commas WITHOUT trimming
    // (Worker::getNextJob → explode(',', $queue)), so this process polls `high` and ` default`.
    queueJob('high', 600);
    queueJob('default', 600);
    worker('web#10', 'high, default');

    $lanes = collect(app(QueueHealth::class)->lanes())->keyBy('queue');
    $snap = app(QueueHealth::class)->snapshot();

    expect($lanes['high']['alive'])->toBeTrue()                     // high drains
        ->and($lanes['high']['workers'])->toBe(['web#10'])
        ->and($lanes['default']['alive'])->toBeFalse()              // ` default` is not `default`
        ->and($lanes['default']['workers'])->toBe([])
        ->and($lanes['default']['down'])->toBeTrue()
        ->and($snap['silent_lanes'])->toBe(['default'])
        ->and($snap['worker_down'])->toBeTrue();

    $w = collect(app(QueueHealth::class)->workers())->firstWhere('worker_id', 'web#10');
    expect($w['lanes'])->toBe(['high', ' default'])                 // verbatim, never tidied
        ->and($w['whitespace_lanes'])->toBe([' default']);
});
