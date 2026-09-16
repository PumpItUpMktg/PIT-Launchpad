<?php

use App\Enums\UserRole;
use App\Filament\Pages\QueueBoard;
use App\Models\QueueWorker;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('is operator-only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(QueueBoard::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(QueueBoard::canAccess())->toBeTrue();
});

it('shows each lane with its worker and each worker with what it is doing, and calls out a lane nobody is polling', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config(['launchpad.town_rank.queue' => 'high', 'launchpad.geo_grid.queue' => 'high', 'launchpad.metrics.queue' => 'default']);
    DB::table('jobs')->insert(['queue' => 'high', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 600, 'created_at' => time() - 600]);
    QueueWorker::create(['worker_id' => 'web#10', 'hostname' => 'web', 'pid' => 10, 'queues' => 'default', 'started_at' => now()->subHour(), 'last_seen_at' => now(), 'jobs_processed' => 12]);
    QueueWorker::create(['worker_id' => 'web#9', 'hostname' => 'web', 'pid' => 9, 'queues' => 'high', 'started_at' => now()->subHours(2), 'last_seen_at' => now()->subMinutes(41), 'stopped_at' => now()->subMinutes(41), 'stop_reason' => 'memory limit exceeded (128 MB)']);

    Livewire::test(QueueBoard::class)
        ->assertOk()
        ->assertSee('No live worker on')
        ->assertSeeHtml('<code>high</code>')
        ->assertSee('Down — jobs waiting, no worker')
        ->assertSee('Live · idle')
        ->assertSee('web#10')
        ->assertSee('web#9')
        ->assertSee('memory limit exceeded (128 MB)')
        ->assertSee('queue:work --queue=high --tries=3');
});

it('clears failed jobs from the page', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{"displayName":"App\\\\Jobs\\\\PublishContent","data":{"command":""}}', 'exception' => 'RuntimeException: WP 401', 'failed_at' => now()]);

    Livewire::test(QueueBoard::class)
        ->assertSee('Failed jobs')
        ->assertSee('WP 401')
        ->call('clearFailedJobs')
        ->assertDontSee('WP 401');

    expect(DB::table('failed_jobs')->count())->toBe(0);
});
