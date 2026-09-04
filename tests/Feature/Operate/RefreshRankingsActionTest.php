<?php

use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateServicePages;
use App\Jobs\RefreshSitePositions;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
    Queue::fake();
});

it('dispatches a one-time positions pull for the current site', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    session(['guided_site_id' => $site->id]); // the locked working tenant (the gate/switcher sets this in prod)
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'status' => 'scored']);

    Livewire::test(OperateServicePages::class)
        ->callAction('refreshRankings')
        ->assertHasNoActionErrors();

    Queue::assertPushed(RefreshSitePositions::class, fn (RefreshSitePositions $job) => $job->siteId === $site->id);
});

it('does not dispatch when the site has no tracked keywords to pull', function () {
    $site = Site::factory()->create(['brand_name' => 'Empty', 'domain_url' => 'https://empty.example']);
    session(['guided_site_id' => $site->id]); // the locked working tenant (the gate/switcher sets this in prod)

    Livewire::test(OperateServicePages::class)
        ->callAction('refreshRankings');

    Queue::assertNotPushed(RefreshSitePositions::class);
});
