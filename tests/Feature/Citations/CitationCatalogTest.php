<?php

use App\Enums\AcquisitionType;
use App\Enums\DirectoryScope;
use App\Enums\MultiLocationPolicy;
use App\Enums\SharedPhonePurpose;
use App\Models\Directory;
use App\Models\DirectoryMarketSignal;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TenantSharedPhone;
use App\Support\CurrentSite;

it('creates a global directory with enum + json casts (no tenant scoping)', function () {
    $dir = Directory::factory()->create([
        'name' => 'BBB', 'scope' => DirectoryScope::National, 'trade_categories' => ['plumbing', 'hvac'],
    ]);

    expect($dir->scope)->toBe(DirectoryScope::National)
        ->and($dir->acquisition_type)->toBe(AcquisitionType::Free)
        ->and($dir->multi_location_policy)->toBe(MultiLocationPolicy::OnePerAddress)
        ->and($dir->trade_categories)->toBe(['plumbing', 'hvac'])
        ->and($dir->is_active)->toBeTrue();

    // Global catalog: readable without any current-site set (no SiteScope on this model).
    CurrentSite::set(Site::factory()->create()->id);
    expect(Directory::count())->toBe(1);
});

it('computes cost-per-value-point and the market-local SEO override', function () {
    $dir = Directory::factory()->paid(12.0)->create(['seo_value' => 60]);
    DirectoryMarketSignal::factory()->create(['directory_id' => $dir->id, 'geo_value' => 'Clifton', 'seo_value_local' => 30]);

    expect($dir->costPerValuePoint())->toBe(0.2)                 // 12 / 60
        ->and($dir->seoValueFor(null))->toBe(60)                 // global
        ->and($dir->seoValueFor('Clifton'))->toBe(30)           // per-market override
        ->and($dir->costPerValuePoint('Clifton'))->toBe(0.4);   // 12 / 30

    // A free directory has no cost per point.
    expect(Directory::factory()->create(['cost_amount' => null, 'seo_value' => 50])->costPerValuePoint())->toBeNull();
});

it('enum helpers classify acquisition type and multi-location policy', function () {
    expect(AcquisitionType::Free->isFree())->toBeTrue()
        ->and(AcquisitionType::Membership->isClientAction())->toBeTrue()
        ->and(AcquisitionType::PaidOneTime->isClientAction())->toBeFalse()
        ->and(MultiLocationPolicy::OnePerBusiness->siblingListingCovers())->toBeTrue()
        ->and(MultiLocationPolicy::OnePerAddress->siblingListingCovers())->toBeFalse()
        ->and(DirectoryScope::Town->isGeoScoped())->toBeTrue()
        ->and(DirectoryScope::National->isGeoScoped())->toBeFalse();
});

it('scopes a NAP profile to its location + tenant (one per location)', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id]);
    $profile = LocationNapProfile::factory()->create(['site_id' => $site->id, 'location_id' => $loc->id, 'phone_primary' => '973-555-0100']);

    expect($profile->location->is($loc))->toBeTrue()
        ->and($profile->phone_primary)->toBe('973-555-0100');

    // Tenant-isolated: another site sees none under its scope.
    CurrentSite::set(Site::factory()->create()->id);
    expect(LocationNapProfile::count())->toBe(0)
        ->and(LocationNapProfile::withoutGlobalScope(SiteScope::class)->count())->toBe(1);
});

it('models a shared phone with null vs owning-location attribution', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id]);

    $corporate = TenantSharedPhone::factory()->create(['site_id' => $site->id, 'phone' => '877-786-7834', 'purpose' => SharedPhonePurpose::Corporate]);
    $owned = TenantSharedPhone::factory()->create(['site_id' => $site->id, 'phone' => '973-555-0199', 'owning_location_id' => $loc->id]);

    expect($corporate->purpose)->toBe(SharedPhonePurpose::Corporate)
        ->and($corporate->isOwned())->toBeFalse()               // null → zero attribution signal
        ->and($owned->isOwned())->toBeTrue()
        ->and($owned->owningLocation->is($loc))->toBeTrue();
});
