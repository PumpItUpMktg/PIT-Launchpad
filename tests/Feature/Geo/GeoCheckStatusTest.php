<?php

use App\Enums\UserRole;
use App\Filament\Widgets\GeoCheckStatusWidget;
use App\Geo\GeoCheckStatus;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('begins, reports running with a start time, and finishes', function () {
    $status = app(GeoCheckStatus::class);

    expect($status->isRunning('site-1'))->toBeFalse();

    $status->begin('site-1');
    expect($status->isRunning('site-1'))->toBeTrue()
        ->and($status->startedAt('site-1'))->not->toBeNull();

    $status->finish('site-1');
    expect($status->isRunning('site-1'))->toBeFalse()
        ->and($status->startedAt('site-1'))->toBeNull();
});

it('tracks the now-contacting cursor and clears it when the run finishes', function () {
    $status = app(GeoCheckStatus::class);
    expect($status->currentContact('site-1'))->toBeNull();

    $status->markContacting('site-1', 'perplexity', 'best sump pump repair in Union', 'Union');
    expect($status->currentContact('site-1'))->toMatchArray(['engine' => 'perplexity', 'prompt' => 'best sump pump repair in Union', 'town' => 'Union']);

    $status->finish('site-1');
    expect($status->currentContact('site-1'))->toBeNull();
});

it('the status widget shows a running check with live progress', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $a = GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'a', 'active' => true]);
    GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'b', 'active' => true]);

    app(GeoCheckStatus::class)->begin($site->id);
    // One reading written since the run started → 1 of (2 prompts × 1 engine in tests) measured.
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $a->id, 'engine' => 'claude', 'cited' => false, 'checked_at' => now()]);

    Livewire::test(GeoCheckStatusWidget::class)
        ->assertSee('Checking AI visibility')
        ->assertSee('Sump Pump Gurus')
        ->assertSee('1/2');
});

it('the status widget shows nothing when no check is running', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();
    GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'a', 'active' => true]);

    Livewire::test(GeoCheckStatusWidget::class)
        ->assertDontSee('Checking AI visibility');
});
