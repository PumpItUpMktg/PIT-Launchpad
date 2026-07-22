<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\SilosStep;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
    config()->set('launchpad.keyword_first.enabled', true);
});

function fringeSpoke(SiloBlueprint $bp, Site $site, string $name): Spoke
{
    return Spoke::create([
        'silo_blueprint_id' => $bp->id, 'site_id' => $site->id, 'name' => $name,
        'silo' => 'Out of Lane', 'is_pillar' => false, 'tag' => SpokeTag::Fringe,
        'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::OwnPage,
    ]);
}

it('promotes a fringe handoff into a real force_page service with its own page', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $bp = SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    $fringe = fringeSpoke($bp, $site, 'Radon Mitigation');

    Livewire::test(SilosStep::class)
        ->set("fringeSilo.{$fringe->id}", 'Foundation Water Problems')
        ->call('buildPageFromFringe', $fringe->id);

    // The fringe candidate is now a core own-page spoke in the chosen topic.
    $fringe->refresh();
    expect($fringe->tag)->toBe(SpokeTag::Core)
        ->and($fringe->silo)->toBe('Foundation Water Problems')
        ->and($fringe->granularity)->toBe(SpokeGranularity::OwnPage)
        ->and($fringe->status)->toBe(SpokeStatus::Offered);

    // And a real, page-guaranteed service now backs it.
    $service = Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstWhere('name', 'Radon Mitigation');
    expect($service)->not->toBeNull()
        ->and($service->force_page)->toBeTrue()
        ->and($service->forced_silo)->toBe('Foundation Water Problems');
});

it('refuses to promote a fringe item without a chosen topic', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $bp = SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    $fringe = fringeSpoke($bp, $site, 'Whole-Home Plumbing Repair');

    Livewire::test(SilosStep::class)->call('buildPageFromFringe', $fringe->id);

    expect($fringe->fresh()->tag)->toBe(SpokeTag::Fringe) // untouched
        ->and(Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
