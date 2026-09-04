<?php

use App\Enums\SizeTier;
use App\Enums\UserRole;
use App\Filament\Pages\LocationsSetup;
use App\Locations\CoveragePanels;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

afterEach(fn () => CurrentSite::clear());

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    app(ActiveTenant::class)->set($this->site->id);
});

it('the Service-area editor renders the Towns tab bar and no cross-tenant site picker', function () {
    Livewire::test(LocationsSetup::class)
        ->assertOk()
        ->assertSee('Service area')
        ->assertSee('Towns board')
        ->assertSee('Tier progression')
        ->assertSee('Link plans')
        ->assertDontSee('Select a site…'); // the old per-page cross-tenant picker is gone
});

it('reads its tenant from the lock, not a picker', function () {
    // No getSiteOptionsProperty any more — the tenancy fold onto ActiveTenant.
    expect(method_exists(LocationsSetup::class, 'getSiteOptionsProperty'))->toBeFalse();

    $page = Livewire::test(LocationsSetup::class);
    expect($page->get('siteId'))->toBe($this->site->id); // mounted from ActiveTenant
});

it('surfaces the tiered-rollout lock per tier band in the coverage panels', function () {
    $location = Location::factory()->create(['site_id' => $this->site->id]);
    CoverageArea::factory()->create([
        'site_id' => $this->site->id,
        'source_location_ids' => [$location->id],
        'size_tier' => SizeTier::Major->value,
    ]);

    $panels = app(CoveragePanels::class)->build($this->site, collect([$location]));
    $locks = $panels['panels'][$location->id]['tier_locks'];

    // Every tier band carries a lock verdict; the top tier (Major) is always buildable → not locked.
    expect(array_keys($locks))->toBe(CoveragePanels::TIERS)
        ->and($locks['major']['locked'])->toBeFalse()
        ->and($locks['major']['reason'])->toBeString();
});
