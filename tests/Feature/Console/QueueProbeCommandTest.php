<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function probeJob(string $queue, array $attrs = []): void
{
    DB::table('jobs')->insert(array_merge([
        'queue' => $queue, 'payload' => '{"displayName":"App\\\\Jobs\\\\PublishContent"}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => time() - 600, 'created_at' => time() - 600,
    ], $attrs));
}

beforeEach(fn () => config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 930]));

it('names the store and the exact row each lane would hand a worker', function () {
    probeJob('high');
    probeJob('default');

    expect(Artisan::call('launchpad:queue-probe'))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('queue connection : database')
        ->toContain('retry_after      : 930s')
        ->toContain('maintenance mode : up')
        ->toContain('would reserve job #')
        ->toContain('is not reading this table')
        ->toContain('Framework-reported size');
});

it('separates a delayed job from a live reservation when nothing is reservable', function () {
    probeJob('default', ['available_at' => time() + 3600]);                    // delayed
    probeJob('default', ['reserved_at' => time(), 'available_at' => time() - 60]); // held by a live worker

    Artisan::call('launchpad:queue-probe', ['--queue' => 'default']);
    $out = Artisan::output();

    expect($out)->toContain('nothing reservable')
        ->toContain('1 still delayed')
        ->toContain('1 held by a live reservation')
        ->not->toContain('would reserve job #');
});

it('reports a reservation that outlived retry_after as reservable again', function () {
    probeJob('high', ['reserved_at' => time() - 2000, 'available_at' => time() - 3000]);

    Artisan::call('launchpad:queue-probe', ['--queue' => 'high']);

    expect(Artisan::output())->toContain('would reserve job #')
        ->toContain('its worker died holding it');
});

it('calls out maintenance mode, which pauses every worker daemon', function () {
    probeJob('default');
    App::shouldReceive('isDownForMaintenance')->andReturn(true);

    Artisan::call('launchpad:queue-probe');

    expect(Artisan::output())->toContain('maintenance mode : DOWN')
        ->toContain('PAUSES while the app is down');
});
