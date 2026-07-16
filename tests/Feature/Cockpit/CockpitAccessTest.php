<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SiteCockpit;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Filament\Widgets\JobHealthWidget;
use App\Filament\Widgets\PipelineFunnelWidget;
use App\Filament\Widgets\PipelineStatsWidget;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('the operator cockpit panel is operator-only', function () {
    Filament::setCurrentPanel('admin');
    $panel = Filament::getPanel('admin');

    expect(User::factory()->create(['role' => UserRole::Operator])->canAccessPanel($panel))->toBeTrue()
        ->and(User::factory()->create(['role' => UserRole::Client])->canAccessPanel($panel))->toBeFalse();
});

test('the portfolio triage list renders for an operator', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    Site::factory()->create();

    Livewire::test(ListSites::class)->assertOk();
});

test('the portfolio card links back to the per-site cockpit (re-linked after the card redesign orphaned it)', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();

    Livewire::test(ListSites::class)->assertTableActionExists('cockpit');

    // The action's URL carries the site drill-down param the cockpit mounts from.
    expect(SiteCockpit::getUrl(['site' => $site->id]))
        ->toContain('/admin/cockpit')
        ->toContain('site='.$site->id);
});

test('the pipeline widgets render', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview]);

    Livewire::test(PipelineStatsWidget::class)->assertOk();
    Livewire::test(PipelineFunnelWidget::class)->assertOk();
    Livewire::test(JobHealthWidget::class)->assertOk();
});
