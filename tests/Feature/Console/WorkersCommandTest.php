<?php

use App\Models\QueueWorker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('prints the lanes and the workers, naming a down lane and how a gone worker stopped', function () {
    config(['launchpad.town_rank.queue' => 'high', 'launchpad.geo_grid.queue' => 'high', 'launchpad.metrics.queue' => 'default']);
    DB::table('jobs')->insert(['queue' => 'high', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 600, 'created_at' => time() - 600]);
    QueueWorker::create(['worker_id' => 'web#9', 'hostname' => 'web', 'pid' => 9, 'queues' => 'high', 'started_at' => now()->subHours(2), 'last_seen_at' => now()->subMinutes(41), 'stopped_at' => now()->subMinutes(41), 'stop_reason' => 'memory limit exceeded (128 MB)']);
    QueueWorker::create(['worker_id' => 'web#10', 'hostname' => 'web', 'pid' => 10, 'queues' => 'default', 'started_at' => now()->subHour(), 'last_seen_at' => now()->subSeconds(5), 'jobs_processed' => 3]);

    // Artisan::output() rather than expectsOutputToContain: the latter matches one substring per written
    // line, and the worker line carries both the id and the stop reason.
    expect(Artisan::call('launchpad:workers'))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('high')->toContain('DOWN — jobs waiting, no worker')
        ->toContain('web#9')->toContain('stopped')->toContain('memory limit exceeded (128 MB)')
        ->toContain('web#10')->toContain('3 done / 0 failed')->toContain('live, idle');
});

it('says so when no worker has reported yet', function () {
    $this->artisan('launchpad:workers')
        ->expectsOutputToContain('none have reported yet')
        ->assertSuccessful();
});
