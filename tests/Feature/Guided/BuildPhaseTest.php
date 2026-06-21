<?php

use App\Enums\BuildStatus;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Build;
use App\Filament\Pages\Guided\Grow;
use App\Models\BuildPage;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 5,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true, 'approved' => true,
    ]);
    SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
});

test('entering Build assembles + ticks: brand-critical pages gate to review, the rest publish', function () {
    Livewire::test(Build::class)->assertOk();

    $review = BuildPage::query()->where('site_id', $this->site->id)->where('status', 'in_review')->pluck('page_key');

    expect($review)->toContain('home')->toContain('about')          // brand-critical, gated
        ->and(BuildPage::query()->where('site_id', $this->site->id)->where('page_key', 'contact')->first()->status)->toBe(BuildStatus::Published)
        ->and(SetupState::query()->where('site_id', $this->site->id)->value('launched'))->toBe(false);
});

test('approving the reviewed pages launches the site and Continue routes to Grow', function () {
    Livewire::test(Build::class)->assertOk(); // tick

    $reviewIds = BuildPage::query()->where('site_id', $this->site->id)->where('status', 'in_review')->pluck('id');

    $page = Livewire::test(Build::class);
    foreach ($reviewIds as $id) {
        $page->call('publishReviewed', $id);
    }
    $page->call('continueToGrow')->assertRedirect(Grow::getUrl());

    expect(SetupState::query()->where('site_id', $this->site->id)->value('launched'))->toBe(true);
});

test('launching flips an onboarding site to Active so the overview stops resuming the wizard', function () {
    // a real onboarding site (CreateSite default) — not the factory's active default
    $this->site->update(['status' => SiteStatus::Onboarding]);

    Livewire::test(Build::class)->assertOk(); // tick
    $reviewIds = BuildPage::query()->where('site_id', $this->site->id)->where('status', 'in_review')->pluck('id');

    $page = Livewire::test(Build::class);
    foreach ($reviewIds as $id) {
        $page->call('publishReviewed', $id);
    }

    expect($this->site->fresh()->status)->toBe(SiteStatus::Active); // off Onboarding

    $page->call('continueToGrow')->assertRedirect(Grow::getUrl());
    expect(SetupState::query()->where('site_id', $this->site->id)->value('current_step'))->toBe(9); // Grow
});

test('Continue is blocked while brand-critical pages are unreviewed', function () {
    $page = Livewire::test(Build::class);
    $page->call('continueToGrow')->assertNoRedirect();

    expect(SetupState::query()->where('site_id', $this->site->id)->value('launched'))->toBe(false);
});
