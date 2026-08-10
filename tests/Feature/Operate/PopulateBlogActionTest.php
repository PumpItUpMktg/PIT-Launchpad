<?php

use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Jobs\PopulateBlog;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

test('Populate blog now dispatches the ingest job for the ACTIVE tenant once the chain is ready', function () {
    Bus::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'rule_set' => ['include_patterns' => ['sump pump']]]);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Trooper']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'sump pump repair cost']);
    session(['guided_site_id' => $site->id]); // the panel-wide active tenant — no per-page switcher

    Livewire::test(OperateBlog::class)->call('populateBlog');

    Bus::assertDispatched(PopulateBlog::class, fn (PopulateBlog $job) => $job->siteId === $site->id);
});

test('with nothing routed it does NOT dispatch and surfaces the reason instead', function () {
    Bus::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG']); // no keywords, no silos
    session(['guided_site_id' => $site->id]);

    Livewire::test(OperateBlog::class)->call('populateBlog');

    Bus::assertNotDispatched(PopulateBlog::class);
});

test('it refuses to populate with no active tenant', function () {
    Bus::fake();
    session()->forget('guided_site_id');

    Livewire::test(OperateBlog::class)->call('populateBlog');

    Bus::assertNotDispatched(PopulateBlog::class);
});

it('keeps $timeout generous but below the queue retry_after (the feed fan-out out-ran the 60s default)', function () {
    $job = new PopulateBlog('x');
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect($job->timeout)->toBeGreaterThanOrEqual(300)  // the HTTP feed fan-out needs minutes, not 60s
        ->and($retryAfter)->toBeGreaterThanOrEqual(630)
        ->and($job->timeout)->toBeLessThan($retryAfter) // no mid-flight re-reservation
        ->and($job->tries)->toBe(1);                     // the funnel dedupes; a failed fetch won't self-heal
});
