<?php

use App\Enums\UserRole;
use App\Filament\Resources\ConnectionsResource\Pages\ListConnections;
use App\Models\GoogleAccount;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http as HttpFacade;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

/** A connected shared grant that can list one GSC + one GA4 property. */
function connectedGrantListing(): void
{
    GoogleAccount::factory()->create();

    HttpFacade::fake([
        '*/webmasters/v3/sites' => HttpFacade::response(['siteEntry' => [['siteUrl' => 'sc-domain:example.com']]]),
        '*/accountSummaries' => HttpFacade::response(['accountSummaries' => [
            ['propertySummaries' => [['property' => 'properties/123', 'displayName' => 'Example GA4']]],
        ]]),
    ]);
}

test('the properties action points a tenant at its GSC + GA4 property from the shared grant', function () {
    connectedGrantListing();
    $site = Site::factory()->create(['brand_name' => 'Acme']);

    Livewire::test(ListConnections::class)
        ->callAction('googleProperties', [
            'site_id' => $site->id,
            'gsc_property' => 'sc-domain:example.com',
            'ga4_property' => 'properties/123',
        ]);

    expect($site->fresh()->gsc_property)->toBe('sc-domain:example.com')
        ->and($site->fresh()->ga4_property)->toBe('properties/123');
});

test('the properties action can clear a tenant back to not-connected', function () {
    connectedGrantListing();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:example.com', 'ga4_property' => 'properties/123']);

    Livewire::test(ListConnections::class)
        ->callAction('googleProperties', ['site_id' => $site->id, 'gsc_property' => null, 'ga4_property' => null]);

    expect($site->fresh()->gsc_property)->toBeNull()
        ->and($site->fresh()->ga4_property)->toBeNull();
});

test('the connections page renders the Google connect + properties actions for an operator', function () {
    Livewire::test(ListConnections::class)
        ->assertOk()
        ->assertActionExists('connectGoogle')
        ->assertActionExists('googleProperties');
});
