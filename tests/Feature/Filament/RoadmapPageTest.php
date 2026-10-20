<?php

use App\Enums\UserRole;
use App\Filament\Pages\RoadmapPage;
use App\Jobs\RefreshGbpProfiles;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/../Roadmap/RoadmapFixtures.php';

beforeEach(function (): void {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('is operator-only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(RoadmapPage::canAccess())->toBeFalse();
});

it('renders the site index and the selected office\'s roadmap, and the refresh button queues the profiles job', function () {
    $w = roadmapWorld();
    session([ActiveTenant::SESSION_KEY => $w['site']->id]);

    Livewire::test(RoadmapPage::class)
        ->assertOk()
        ->assertSee('Hackettstown · 3 towns')
        ->assertSee('Refresh the Google Business Profiles')
        ->assertSee('sump pump installation')
        ->assertSee('Oriole Basement Waterproofing')
        ->assertSee('1 of 3')
        ->call('refreshProfiles');

    Queue::assertPushed(RefreshGbpProfiles::class, fn (RefreshGbpProfiles $job): bool => $job->siteId === (string) $w['site']->id);
});
