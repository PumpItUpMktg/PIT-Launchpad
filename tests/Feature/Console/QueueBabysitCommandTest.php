<?php

use App\Models\QueueWorker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** A job that does nothing but record that a worker ran it. */
class BabysitProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public static int $ran = 0;

    public function handle(): void
    {
        self::$ran++;
    }
}

function babysitJob(string $queue, int $ageSeconds = 600): void
{
    DB::table('jobs')->insert([
        'queue' => $queue, 'payload' => '{"displayName":"App\\\\Jobs\\\\PublishContent"}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => time() - $ageSeconds, 'created_at' => time() - $ageSeconds,
    ]);
}

beforeEach(fn () => BabysitProbeJob::$ran = 0);

beforeEach(fn () => config([
    'queue.default' => 'database',
    'launchpad.town_rank.queue' => 'high', 'launchpad.geo_grid.queue' => 'high', 'launchpad.metrics.queue' => 'default',
]));

it('does nothing while a live worker is on the lane', function () {
    babysitJob('default');
    QueueWorker::create(['worker_id' => 'app#1', 'hostname' => 'app', 'pid' => 1, 'connection' => 'database',
        'queues' => 'default', 'started_at' => now()->subHour(), 'last_seen_at' => now()]);

    Artisan::call('launchpad:queue-babysit');

    expect(Artisan::output())->toContain('nothing to cover for');
    expect(DB::table('jobs')->count())->toBe(1);   // untouched — the real worker's job to take
});

it('names the lanes it would cover for, and starts nothing on a dry run', function () {
    babysitJob('default');
    babysitJob('high');
    // A worker exists but polls another connection, so it can consume neither lane.
    QueueWorker::create(['worker_id' => 'min#1', 'hostname' => 'min', 'pid' => 1, 'connection' => 'cloud',
        'queues' => 'default', 'started_at' => now()->subHour(), 'last_seen_at' => now()]);

    Artisan::call('launchpad:queue-babysit', ['--dry-run' => true]);

    expect(Artisan::output())->toContain('No live worker on default,high')
        ->toContain('2 job(s) waiting')
        ->toContain('Dry run');
    expect(DB::table('jobs')->count())->toBe(2);
});

it('leaves a backlog alone until it has aged past the threshold', function () {
    babysitJob('default', ageSeconds: 30);   // fresh: a healthy worker would be picking this up right now

    Artisan::call('launchpad:queue-babysit', ['--stale' => 3]);

    expect(Artisan::output())->toContain('nothing to cover for');
});

it('drains the lane in-process when no worker can', function () {
    // Real queued work, so the drain is the framework's worker actually running these — not a hand-written
    // payload whose outcome depends on how it fails to unserialize.
    Queue::connection('database')->pushOn('default', new BabysitProbeJob);
    Queue::connection('database')->pushOn('default', new BabysitProbeJob);
    DB::table('jobs')->update(['available_at' => time() - 600, 'created_at' => time() - 600]);
    expect(DB::table('jobs')->count())->toBe(2)->and(BabysitProbeJob::$ran)->toBe(0);

    Artisan::call('launchpad:queue-babysit', ['--max-time' => 30]);

    // Both ran and the lane is empty: the scheduler covered for the worker that was not there.
    expect(BabysitProbeJob::$ran)->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Artisan::output())->toContain('Draining in-process');
});
