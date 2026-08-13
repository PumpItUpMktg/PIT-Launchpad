<?php

use App\Enums\UserRole;
use App\Filament\Pages\Gathering\ServicesStep;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Models\ConversionConfig;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Onboarding\IntakeCollector;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** A tenant whose ConversionConfig sets (or clears) the new-services referral default. */
function refDefaultSite(bool $default): Site
{
    $site = Site::factory()->create();
    ConversionConfig::factory()->create(['site_id' => $site->id, 'referral_default' => $default]);

    return $site;
}

function refModeOf(Site $site, string $name): ?bool
{
    $v = Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->value('referral_mode');

    return $v === null ? null : (bool) $v;
}

// ── The model hook: the single point every non-Filament creation path flows through ──────────────────

it('seeds referral_mode from the tenant default on any Eloquent create (Service::create AND new Service)', function () {
    $site = refDefaultSite(true);

    $viaCreate = Service::create(['site_id' => $site->id, 'name' => 'Roofing']);
    $viaNew = (new Service)->forceFill(['site_id' => $site->id, 'name' => 'HVAC']);
    $viaNew->save();

    expect($viaCreate->referral_mode)->toBeTrue()
        ->and($viaNew->fresh()->referral_mode)->toBeTrue();
});

it('leaves referral_mode false when the tenant default is off or no config exists', function () {
    $off = refDefaultSite(false);
    $none = Site::factory()->create(); // no ConversionConfig at all

    // The hook doesn't touch the attribute, so it persists at the column default (false).
    Service::create(['site_id' => $off->id, 'name' => 'A']);
    Service::create(['site_id' => $none->id, 'name' => 'B']);

    expect(refModeOf($off, 'A'))->toBeFalse()
        ->and(refModeOf($none, 'B'))->toBeFalse();
});

it('never overrides an explicit per-service value, even with the default on', function () {
    $site = refDefaultSite(true);

    // The one service Storm Ready actually performs — explicitly off, must persist despite the default.
    expect(Service::create(['site_id' => $site->id, 'name' => 'Home Assessment', 'referral_mode' => false])->referral_mode)->toBeFalse();
});

it('does not retroactively change existing services when the default flips on', function () {
    $site = refDefaultSite(false);
    $existing = Service::create(['site_id' => $site->id, 'name' => 'Old']); // created while off → false

    ConversionConfig::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->update(['referral_default' => true]);

    expect($existing->fresh()->referral_mode)->toBeFalse()               // untouched
        ->and(Service::create(['site_id' => $site->id, 'name' => 'New'])->referral_mode)->toBeTrue(); // new one defaults on
});

// ── The five creation paths ──────────────────────────────────────────────────────────────────────────

// Path 1 — the §7a IntakeCollector service catalog (Service::create).
it('path: IntakeCollector::saveServiceCatalog respects the default', function () {
    $site = refDefaultSite(true);

    $services = app(IntakeCollector::class)->saveServiceCatalog($site, [['name' => 'Plumbing']]);

    expect($services->first()->referral_mode)->toBeTrue();
});

// Paths 2/3/4 — the live Gathering ServicesStep (addService / addSuggestion / addSubService all `new Service`).
it('path: ServicesStep::addService respects the default', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
    $site = refDefaultSite(true);
    session(['guided_site_id' => $site->id]);

    $page = new ServicesStep;
    $page->siteId = $site->id;
    $page->newService = 'Roof Replacement';
    $page->addService();

    expect(refModeOf($site, 'Roof Replacement'))->toBeTrue();
});

it('path: ServicesStep::addSubService respects the default', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
    $site = refDefaultSite(true);
    session(['guided_site_id' => $site->id]);
    $parent = Service::create(['site_id' => $site->id, 'name' => 'Roofing']);

    $page = new ServicesStep;
    $page->siteId = $site->id;
    $page->newChild = [$parent->id => 'Flat Roofs'];
    $page->addSubService($parent->id);

    expect(refModeOf($site, 'Flat Roofs'))->toBeTrue();
});

// Path 5 — the ServiceResource Filament create (submits the toggle explicitly → covered by the toggle default).
it('path: ServiceResource create form defaults referral_mode to the tenant setting', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = refDefaultSite(true);
    CurrentSite::set($site->id);

    Livewire::test(CreateService::class)->assertFormSet(['referral_mode' => true]);

    // And with the default off, the create form is off (regression).
    $off = refDefaultSite(false);
    CurrentSite::set($off->id);
    Livewire::test(CreateService::class)->assertFormSet(['referral_mode' => false]);
});
