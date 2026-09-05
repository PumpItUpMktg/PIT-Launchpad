<?php

use App\Enums\UserRole;
use App\Filament\Resources\ConnectionsResource\Pages\ListConnections;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('services.bing.api_key', 'agency-key'); // action is disabled without a key
});

test('the Bing site action points a tenant at its verified BWT site URL (trailing slash trimmed)', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example', 'bing_site_url' => null]);
    app(ActiveTenant::class)->set($site->id); // the action targets the LOCKED tenant, not a form picker

    Livewire::test(ListConnections::class)
        ->callAction('bingSite', ['bing_site_url' => 'https://spg.example/']);

    expect($site->fresh()->bing_site_url)->toBe('https://spg.example');
});

test('the Bing site action clears the tenant back to mock when left blank', function () {
    $site = Site::factory()->create(['bing_site_url' => 'https://spg.example']);
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(ListConnections::class)
        ->callAction('bingSite', ['bing_site_url' => '']);

    expect($site->fresh()->bing_site_url)->toBeNull();
});

test('the connections page renders the Bing site action for an operator', function () {
    Livewire::test(ListConnections::class)
        ->assertOk()
        ->assertActionExists('bingSite');
});

test('the Bing site action is disabled without an agency API key', function () {
    config()->set('services.bing.api_key', '');

    Livewire::test(ListConnections::class)
        ->assertActionDisabled('bingSite');
});
