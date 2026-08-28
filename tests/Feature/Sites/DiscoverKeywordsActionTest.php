<?php

use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Jobs\DiscoverKeywords;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('exposes the Discover keywords action', function () {
    Livewire::test(ListSites::class)->assertTableActionExists('discover_keywords');
});

it('queues §5 keyword generation for the tenant (generate: true, off the web request)', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);

    Livewire::test(ListSites::class)->callTableAction('discover_keywords', $site);

    Queue::assertPushed(DiscoverKeywords::class, fn (DiscoverKeywords $job): bool => $job->siteId === $site->id);
});
