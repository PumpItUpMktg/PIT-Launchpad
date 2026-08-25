<?php

use App\Enums\GeoCheckAction;
use App\Enums\UserRole;
use App\Filament\Widgets\GeoCheckActivityWidget;
use App\Models\GeoCheckEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

afterEach(fn () => CurrentSite::clear());

beforeEach(fn () => Filament::setCurrentPanel('admin'));

function checkEvent(Site $site, string $runId, GeoCheckAction $action, array $extra = []): GeoCheckEvent
{
    return GeoCheckEvent::create(array_merge([
        'site_id' => $site->id, 'run_id' => $runId, 'engine' => 'claude', 'action' => $action->value,
    ], $extra));
}

it('shows the latest run\'s activity with a count summary', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();

    checkEvent($site, 'run-1', GeoCheckAction::Measured, ['town' => 'Union', 'cited' => false, 'competitors' => ['Acme Plumbing']]);
    checkEvent($site, 'run-1', GeoCheckAction::SkippedFresh, ['town' => 'Clifton']);
    checkEvent($site, 'run-1', GeoCheckAction::Deferred, ['town' => 'Edison']);

    Livewire::test(GeoCheckActivityWidget::class)
        ->assertSee('GEO check activity')
        ->assertSee('Union')
        ->assertSee('Acme Plumbing')     // competitor on the absent measured step
        ->assertSee('Skipped (fresh)')
        ->assertSee('Deferred (budget)');
});

it('renders nothing when there is no activity yet', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    Livewire::test(GeoCheckActivityWidget::class)->assertDontSee('GEO check activity');
});

it('the prune command deletes events past the retention window', function () {
    $site = Site::factory()->create();
    $old = checkEvent($site, 'old-run', GeoCheckAction::Measured);
    $old->forceFill(['created_at' => now()->subDays(30)])->save();
    $recent = checkEvent($site, 'new-run', GeoCheckAction::Measured);

    config(['launchpad.geo.events.retention_days' => 7]);
    $this->artisan('sandhog:prune-geo-events')->assertSuccessful();

    $remaining = GeoCheckEvent::withoutGlobalScope(SiteScope::class)->pluck('id')->all();
    expect($remaining)->toBe([$recent->id]);
});
