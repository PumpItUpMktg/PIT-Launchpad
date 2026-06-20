<?php

use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Structure;
use App\Filament\Pages\Overview;
use App\Filament\Pages\SiteCockpit;
use App\Filament\Resources\SiteResource\Pages\CreateSite;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

test('the overview is the panel landing (slug /)', function () {
    expect(Overview::getUrl())->toBe(Filament::getPanel('admin')->getUrl());
});

test('a card per site renders with the New site on-ramp', function () {
    Site::factory()->create(['brand_name' => 'Alpha', 'status' => SiteStatus::Live]);
    Site::factory()->create(['brand_name' => 'Beta', 'status' => SiteStatus::Onboarding]);

    Livewire::test(Overview::class)
        ->assertOk()
        ->assertSee('Alpha')
        ->assertSee('Beta')
        ->assertSee('New site');
});

test('an onboarding card shows wizard % and resumes at the current step; a live card → cockpit', function () {
    $live = Site::factory()->create(['brand_name' => 'LiveCo', 'status' => SiteStatus::Live]);
    $onb = Site::factory()->create(['brand_name' => 'OnbCo', 'status' => SiteStatus::Onboarding]);
    SetupState::query()->create(['site_id' => $onb->id, 'current_step' => 5]); // Structure (5 of 7)

    $cards = collect(Livewire::test(Overview::class)->instance()->sites);

    $liveCard = $cards->firstWhere('id', $live->id);
    $onbCard = $cards->firstWhere('id', $onb->id);

    expect($liveCard['onboarding'])->toBeFalse()
        ->and($liveCard['url'])->toBe(SiteCockpit::getUrl(['site' => $live->id]))
        ->and($onbCard['onboarding'])->toBeTrue()
        ->and($onbCard['pct'])->toBe((int) round(5 / 7 * 100))             // ~71%
        ->and($onbCard['url'])->toBe(Structure::getUrl(['site' => $onb->id])); // resume at step 5
});

test('the New site button points at the single create on-ramp', function () {
    expect(Livewire::test(Overview::class)->instance()->newSiteUrl())->toBe(CreateSite::getUrl());
});
