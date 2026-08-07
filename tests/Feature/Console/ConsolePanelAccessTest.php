<?php

use App\Filament\Console\Pages\ConsoleHome;
use App\Models\User;
use App\Security\Capability;
use Filament\Facades\Filament;

test('the console panel admits Super Admin and Site Admin, and no one else', function () {
    $console = Filament::getPanel('console');

    $admin = User::factory()->admin()->create();
    $operator = User::factory()->create(); // Operator = Super Admin tier
    $siteAdmin = User::factory()->siteAdmin()->create();
    $client = User::factory()->client()->create();

    expect($admin->canAccessPanel($console))->toBeTrue()
        ->and($operator->canAccessPanel($console))->toBeTrue()
        ->and($siteAdmin->canAccessPanel($console))->toBeTrue()
        ->and($client->canAccessPanel($console))->toBeFalse();
});

test('adding the console panel does not change existing panel access', function () {
    $admin = Filament::getPanel('admin');
    $client = Filament::getPanel('client');

    $operator = User::factory()->create();
    $clientUser = User::factory()->client()->create();
    $siteAdmin = User::factory()->siteAdmin()->create();

    // Operators still reach /admin only; clients still reach /portal only — unchanged.
    expect($operator->canAccessPanel($admin))->toBeTrue()
        ->and($operator->canAccessPanel($client))->toBeFalse()
        ->and($clientUser->canAccessPanel($client))->toBeTrue()
        ->and($clientUser->canAccessPanel($admin))->toBeFalse()
        // The new Site Admin role reaches neither existing panel — only the console.
        ->and($siteAdmin->canAccessPanel($admin))->toBeFalse()
        ->and($siteAdmin->canAccessPanel($client))->toBeFalse();
});

test('the console home reflects the tier: Super Admin holds every capability, Site Admin only operate', function () {
    $this->actingAs(User::factory()->create()); // Super Admin (Operator)
    $superBoard = (new ConsoleHome)->getBoardProperty();
    expect($superBoard['is_super'])->toBeTrue();
    foreach ($superBoard['groups'] as $group) {
        foreach ($group['items'] as $item) {
            expect($item['held'])->toBeTrue();
        }
    }

    $this->actingAs(User::factory()->siteAdmin()->create());
    $siteBoard = (new ConsoleHome)->getBoardProperty();
    expect($siteBoard['is_super'])->toBeFalse();
    foreach ($siteBoard['groups'] as $group) {
        $allHeld = collect($group['items'])->every(fn (array $i): bool => $i['held']);
        $noneHeld = collect($group['items'])->every(fn (array $i): bool => ! $i['held']);
        // Site Admin: the operate group is fully held; recover/admin fully locked.
        expect($group['key'] === 'operate' ? $allHeld : $noneHeld)->toBeTrue();
    }
});

test('capability groups cover every capability exactly once', function () {
    $grouped = collect(Capability::cases())->groupBy(fn (Capability $c): string => $c->group());
    expect($grouped->keys()->sort()->values()->all())->toBe(['admin', 'operate', 'recover'])
        ->and($grouped->flatten()->count())->toBe(count(Capability::cases()));
});
