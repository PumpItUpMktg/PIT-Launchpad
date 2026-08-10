<?php

use App\Filament\Console\Pages\Corrections;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ccFailedJob(): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Boom',
        'failed_at' => now(),
    ]);
}

it('is reachable only by a Super Admin', function () {
    $this->actingAs(User::factory()->create()); // Operator = Super Admin
    expect(Corrections::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->siteAdmin()->create());
    expect(Corrections::canAccess())->toBeFalse();
});

it('clears failed jobs for a Super Admin', function () {
    $this->actingAs(User::factory()->create());
    ccFailedJob();
    expect(DB::table('failed_jobs')->count())->toBe(1);

    (new Corrections)->clearFailed();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

it('unlocks a protected page', function () {
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();
    $page = Content::factory()->create([
        'site_id' => $site->id, 'title' => 'Stuck page', 'locked' => true, 'locally_edited' => true,
    ]);

    $console = new Corrections;
    $console->siteId = $site->id;

    expect(collect($console->getLockedProperty())->pluck('id')->all())->toBe([$page->id]);

    $console->unlock($page->id);

    expect($page->fresh()->locked)->toBeFalse()
        ->and($page->fresh()->locally_edited)->toBeFalse();
});

it('re-syncs chrome, pages, and silo categories to WordPress for a Super Admin', function () {
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();

    Artisan::shouldReceive('call')->once()
        ->with('launchpad:sync-site-profile', ['site' => $site->id])->andReturn(0);
    Artisan::shouldReceive('call')->once()
        ->with('launchpad:repush-published', ['--site' => $site->id, '--kind' => 'all'])->andReturn(0);
    Artisan::shouldReceive('call')->once()
        ->with('launchpad:sync-silo-categories', ['site' => $site->id, '--repush-content' => true])->andReturn(0);

    $console = new Corrections;
    $console->siteId = $site->id;
    $console->syncChrome();
    $console->syncPages();
    $console->syncSilos();
});

it('the re-sync actions are no-ops with no site selected', function () {
    $this->actingAs(User::factory()->create());
    Artisan::spy();

    $console = new Corrections;
    $console->siteId = null;
    $console->syncChrome();
    $console->syncPages();
    $console->syncSilos();

    Artisan::shouldNotHaveReceived('call');
});

it('the re-sync actions are gated off a Site Admin (recover capability)', function () {
    $this->actingAs(User::factory()->siteAdmin()->create());
    $site = Site::factory()->create();
    Artisan::spy();

    $console = new Corrections;
    $console->siteId = $site->id;
    $console->syncChrome();
    $console->syncPages();
    $console->syncSilos();

    Artisan::shouldNotHaveReceived('call');
});

it('toggles the per-tenant weather alert bar for a Super Admin', function () {
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create(['weather_alert' => false]);

    $console = new Corrections;
    $console->siteId = $site->id;
    expect($console->getWeatherAlertEnabledProperty())->toBeFalse();

    $console->toggleWeatherAlert();
    expect($site->fresh()->weather_alert)->toBeTrue()
        ->and($console->getWeatherAlertEnabledProperty())->toBeTrue();

    $console->toggleWeatherAlert();
    expect($site->fresh()->weather_alert)->toBeFalse();
});

it('does not toggle the weather bar with no site selected or for a Site Admin', function () {
    // No site selected → no-op.
    $this->actingAs(User::factory()->create());
    $console = new Corrections;
    $console->siteId = null;
    $console->toggleWeatherAlert(); // must not throw

    // Site Admin lacks the engine-controls capability → no-op.
    $this->actingAs(User::factory()->siteAdmin()->create());
    $site = Site::factory()->create(['weather_alert' => false]);
    $console = new Corrections;
    $console->siteId = $site->id;
    $console->toggleWeatherAlert();

    expect($site->fresh()->weather_alert)->toBeFalse();
});

it('does nothing when a Site Admin invokes a correction', function () {
    $this->actingAs(User::factory()->siteAdmin()->create());
    ccFailedJob();

    (new Corrections)->clearFailed(); // capability gate → no-op

    expect(DB::table('failed_jobs')->count())->toBe(1);
});
