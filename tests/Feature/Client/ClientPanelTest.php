<?php

use App\Client\ClientAccess;
use App\Client\ClientContext;
use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Tests\Support\ClientHarness;

test('the client panel is client-only and the operator panel operator-only', function () {
    $clientPanel = Filament::getPanel('client');
    $adminPanel = Filament::getPanel('admin');

    $client = User::factory()->create(['role' => UserRole::Client]);
    $operator = User::factory()->create(['role' => UserRole::Operator]);

    expect($client->canAccessPanel($clientPanel))->toBeTrue()
        ->and($client->canAccessPanel($adminPanel))->toBeFalse()
        ->and($operator->canAccessPanel($adminPanel))->toBeTrue()
        ->and($operator->canAccessPanel($clientPanel))->toBeFalse();
});

test('a client sees only their Account sites and never another Account', function () {
    ['user' => $clientA, 'site' => $siteA] = ClientHarness::make();
    ['site' => $siteB] = ClientHarness::make();

    $access = app(ClientAccess::class);

    expect($access->sites($clientA)->pluck('id')->all())->toBe([$siteA->id])
        ->and($access->canSee($clientA, $siteA))->toBeTrue()
        ->and($access->canSee($clientA, $siteB))->toBeFalse();
});

test('per-Account white-label branding drives the panel context', function () {
    ['user' => $client] = ClientHarness::make([
        'brand_name' => 'Apex Marketing', 'primary_color' => '#112233', 'logo_url' => 'https://cdn/apex.svg',
    ]);
    $this->actingAs($client);

    $branding = app(ClientContext::class)->branding();

    expect($branding['name'])->toBe('Apex Marketing')
        ->and($branding['primary'])->toBe('#112233')
        ->and($branding['logo_url'])->toBe('https://cdn/apex.svg');
});

test('the site switcher selects an owned site and falls back to the first', function () {
    ['user' => $client, 'account' => $account, 'site' => $first] = ClientHarness::make();
    $second = Site::factory()->create(['account_id' => $account->id]);

    $access = app(ClientAccess::class);

    // A valid selection is honored.
    expect($access->currentSite($client, $second->id)->id)->toBe($second->id);
    // A foreign / invalid selection falls back to an owned site.
    expect($access->currentSite($client, 'not-mine')->account_id)->toBe($account->id)
        ->and([$first->id, $second->id])->toContain($access->currentSite($client, null)->id);
});
