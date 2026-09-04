<?php

use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ReviewImportPage;
use App\Filament\Resources\ReviewCaptureResource\Pages\ListReviews;
use App\Models\Review;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

afterEach(fn () => CurrentSite::clear());

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
});

it('presents the review lifecycle as three tabs plus an Import header action', function () {
    Livewire::test(ListReviews::class)
        ->assertOk()
        ->assertSee('Awaiting approval')
        ->assertSee('Needs market')
        ->assertSee('Published')
        ->assertSee('Import reviews')
        ->assertSeeHtml(ReviewImportPage::getUrl()); // the Import action links to the dedicated flow
});

it('filters each tab to its slice of the lifecycle', function () {
    $awaiting = Review::factory()->create(['site_id' => $this->site->id, 'status' => ReviewStatus::Pending, 'needs_location' => false]);
    $needsMarket = Review::factory()->create(['site_id' => $this->site->id, 'status' => ReviewStatus::Pending, 'needs_location' => true]);
    $published = Review::factory()->published()->create(['site_id' => $this->site->id, 'needs_location' => false]);

    // Awaiting approval = pending reviews (a needs-market review is still pending, so it shows here too).
    Livewire::test(ListReviews::class)
        ->set('activeTab', 'awaiting')
        ->assertCanSeeTableRecords([$awaiting, $needsMarket])
        ->assertCanNotSeeTableRecords([$published]);

    // Needs market = no location assigned yet (needs_location), regardless of status.
    Livewire::test(ListReviews::class)
        ->set('activeTab', 'needs_market')
        ->assertCanSeeTableRecords([$needsMarket])
        ->assertCanNotSeeTableRecords([$awaiting, $published]);

    // Published = live reviews.
    Livewire::test(ListReviews::class)
        ->set('activeTab', 'published')
        ->assertCanSeeTableRecords([$published])
        ->assertCanNotSeeTableRecords([$awaiting, $needsMarket]);
});
