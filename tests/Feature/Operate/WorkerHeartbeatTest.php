<?php

use App\Models\QueueWorker;
use App\Operate\WorkerHeartbeat;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Queue\WorkerStopReason;

function fakeJob(string $name, string $queue = 'high'): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('getQueue')->andReturn($queue);

    return $job;
}

it('reports a worker from its first loop tick: the lane list, then the job it is on, the tally, and how it stopped', function () {
    $beat = app(WorkerHeartbeat::class);

    // A job event before any loop tick (the sync driver inside a request) creates no worker.
    $beat->processing(new JobProcessing('sync', fakeJob('App\\Jobs\\PublishContent', 'default')));
    expect(QueueWorker::count())->toBe(0);

    $beat->looping(new Looping('database', 'high,default'));
    $w = QueueWorker::sole();
    expect($w->worker_id)->toBe(WorkerHeartbeat::workerId())
        ->and($w->queues)->toBe('high,default')
        ->and($w->lanes())->toBe(['high', 'default'])
        ->and($w->connection)->toBe('database')
        ->and($w->current_job)->toBeNull()
        ->and($w->stopped_at)->toBeNull();

    $beat->processing(new JobProcessing('database', fakeJob('App\\Jobs\\IngestTownRankScans', 'high')));
    $w->refresh();
    expect($w->current_job)->toBe('App\\Jobs\\IngestTownRankScans')
        ->and($w->current_queue)->toBe('high')
        ->and($w->current_job_started_at)->not->toBeNull();

    $beat->processed(new JobProcessed('database', fakeJob('App\\Jobs\\IngestTownRankScans', 'high')));
    $w->refresh();
    expect($w->current_job)->toBeNull()->and($w->jobs_processed)->toBe(1)->and($w->jobs_failed)->toBe(0);

    $beat->processing(new JobProcessing('database', fakeJob('App\\Jobs\\PublishContent', 'default')));
    $beat->failed(new JobFailed('database', fakeJob('App\\Jobs\\PublishContent', 'default'), new RuntimeException('WP 401')));
    $w->refresh();
    expect($w->current_job)->toBeNull()->and($w->jobs_processed)->toBe(1)->and($w->jobs_failed)->toBe(1);

    $beat->stopping(new WorkerStopping(12, new WorkerOptions(memory: 128), WorkerStopReason::MaxMemoryExceeded, 2, null, 131.4));
    $w->refresh();
    expect($w->stopped_at)->not->toBeNull()
        ->and($w->stop_reason)->toBe('memory limit exceeded (128 MB)')
        ->and($w->memory_mb)->toBe(131);
});

it('names every stop reason in operator words, falling back to the exit status', function () {
    $reason = fn (WorkerStopping $e): string => WorkerHeartbeat::stopReason($e);

    expect($reason(new WorkerStopping(0, null, WorkerStopReason::MaxTimeExceeded)))->toBe('max-time reached')
        ->and($reason(new WorkerStopping(1, null, WorkerStopReason::TimedOut)))->toBe('a job ran past its timeout')
        ->and($reason(new WorkerStopping(0, null, WorkerStopReason::QueueEmpty)))->toBe('queue empty (stop-when-empty)')
        ->and($reason(new WorkerStopping(0, null, WorkerStopReason::ReceivedRestartSignal)))->toBe('queue:restart signal')
        ->and($reason(new WorkerStopping(0, null, WorkerStopReason::Interrupted)))->toBe('interrupted (SIGTERM / deploy)')
        ->and($reason(new WorkerStopping(0, null, WorkerStopReason::LostConnection)))->toBe('lost the database connection')
        ->and($reason(new WorkerStopping(12)))->toBe('memory limit exceeded')
        ->and($reason(new WorkerStopping(1)))->toBe('error exit (a job timeout or a loop error)')
        ->and($reason(new WorkerStopping(0)))->toBe('exited cleanly');
});

it('throttles the loop heartbeat, and a restarted process (same host + pid) starts a fresh row', function () {
    $beat = app(WorkerHeartbeat::class);
    $beat->looping(new Looping('database', 'high'));
    $w = QueueWorker::sole();
    QueueWorker::query()->whereKey($w->id)->update(['last_seen_at' => now()->subMinutes(10)]);

    // Within the throttle window a tick writes nothing.
    $beat->looping(new Looping('database', 'high'));
    expect($w->refresh()->last_seen_at->lt(now()->subMinutes(9)))->toBeTrue();

    // A new process object (as after a restart) re-registers the same worker id in place — one row, reset tally.
    QueueWorker::query()->whereKey($w->id)->update(['jobs_processed' => 40, 'stopped_at' => now(), 'stop_reason' => 'max-time reached']);
    (new WorkerHeartbeat)->looping(new Looping('database', 'high,default'));
    expect(QueueWorker::count())->toBe(1);
    $w->refresh();
    expect($w->jobs_processed)->toBe(0)->and($w->stopped_at)->toBeNull()->and($w->queues)->toBe('high,default');
});

it('prunes rows older than the retention window when a worker starts', function () {
    QueueWorker::create(['worker_id' => 'old#1', 'hostname' => 'old', 'pid' => 1, 'queues' => 'default', 'started_at' => now()->subDays(9), 'last_seen_at' => now()->subDays(8)]);
    QueueWorker::create(['worker_id' => 'recent#2', 'hostname' => 'recent', 'pid' => 2, 'queues' => 'default', 'started_at' => now()->subDays(2), 'last_seen_at' => now()->subDays(2)]);

    app(WorkerHeartbeat::class)->looping(new Looping('database', 'high'));

    expect(QueueWorker::pluck('worker_id')->sort()->values()->all())->toBe(['recent#2', WorkerHeartbeat::workerId()]);
});

it('is wired to the queue events', function () {
    event(new Looping('database', 'high'));
    expect(QueueWorker::where('queues', 'high')->exists())->toBeTrue();
});

it('records a job that overran its timeout, because that kill is not a clean stop', function () {
    $beat = app(WorkerHeartbeat::class);
    $beat->looping(new Looping('database', 'high'));
    $beat->processing(new JobProcessing('database', fakeJob('App\\Jobs\\IngestCoverageScans', 'high')));

    // Laravel kills the worker process for an overrun — WorkerStopping never fires, so without this the row
    // would sit alive-looking, holding a job, with no reason: the silent worker we kept seeing.
    $beat->timedOut(new JobTimedOut('database', fakeJob('App\\Jobs\\IngestCoverageScans', 'high')));

    $w = QueueWorker::sole();
    expect($w->stopped_at)->not->toBeNull()
        ->and($w->stop_reason)->toBe('killed: App\\Jobs\\IngestCoverageScans ran past its timeout');
});

it('is wired to the timeout event', function () {
    app(WorkerHeartbeat::class)->looping(new Looping('database', 'high'));
    event(new JobTimedOut('database', fakeJob('App\\Jobs\\PublishContent', 'high')));

    expect(QueueWorker::sole()->stop_reason)->toContain('ran past its timeout');
});
