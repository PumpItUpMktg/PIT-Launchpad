<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Security\Capability;
use App\Security\RoleCapabilities;

/** The OPERATE set a Site Admin is allowed; everything else is Super-Admin-only. */
const OPERATE_CAPS = [
    Capability::ViewDashboards,
    Capability::EditContent,
    Capability::ApproveContent,
    Capability::GenerateContent,
    Capability::PublishContent,
];

it('grants the internal Super Admin tier every capability', function (UserRole $role) {
    foreach (Capability::cases() as $capability) {
        expect(RoleCapabilities::allows($role, $capability))->toBeTrue();
    }
    expect($role->isSuperAdmin())->toBeTrue();
})->with([
    'admin' => UserRole::Admin,
    'operator' => UserRole::Operator,
]);

it('grants a Site Admin the operate set but none of the recover/admin powers', function () {
    foreach (OPERATE_CAPS as $capability) {
        expect(RoleCapabilities::allows(UserRole::SiteAdmin, $capability))->toBeTrue();
    }

    $superAdminOnly = array_filter(
        Capability::cases(),
        fn (Capability $c): bool => ! in_array($c, OPERATE_CAPS, true),
    );
    expect($superAdminOnly)->not->toBeEmpty();
    foreach ($superAdminOnly as $capability) {
        expect(RoleCapabilities::allows(UserRole::SiteAdmin, $capability))->toBeFalse();
    }

    expect(UserRole::SiteAdmin->isSuperAdmin())->toBeFalse()
        ->and(UserRole::SiteAdmin->isSiteAdmin())->toBeTrue();
});

it('every recover and admin capability is Super-Admin-only', function () {
    foreach (Capability::cases() as $capability) {
        if (in_array($capability->group(), ['recover', 'admin'], true)) {
            expect(RoleCapabilities::allows(UserRole::SiteAdmin, $capability))->toBeFalse()
                ->and(RoleCapabilities::allows(UserRole::Client, $capability))->toBeFalse()
                ->and(RoleCapabilities::allows(UserRole::Operator, $capability))->toBeTrue();
        }
    }
});

it('grants a read-only Client no operate/admin capabilities', function () {
    expect(RoleCapabilities::for(UserRole::Client))->toBe([]);
});

it('exposes capability checks on the User model', function () {
    $siteAdmin = User::factory()->siteAdmin()->make();
    $superAdmin = User::factory()->make(); // default Operator

    expect($siteAdmin->isSiteAdmin())->toBeTrue()
        ->and($siteAdmin->isSuperAdmin())->toBeFalse()
        ->and($siteAdmin->hasCapability(Capability::PublishContent))->toBeTrue()
        ->and($siteAdmin->hasCapability(Capability::UnfreezeQueue))->toBeFalse();

    expect($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($superAdmin->hasCapability(Capability::UnfreezeQueue))->toBeTrue()
        ->and($superAdmin->hasCapability(Capability::ManageUsers))->toBeTrue();
});

it('does not change existing panel access or staff grouping', function () {
    // Regression guard for the additive change: existing roles are unaffected.
    expect(UserRole::Admin->isStaff())->toBeTrue()
        ->and(UserRole::Operator->isStaff())->toBeTrue()
        ->and(UserRole::Client->isStaff())->toBeFalse()
        // The new role is NOT staff — it cannot reach the operator panel via the existing gate.
        ->and(UserRole::SiteAdmin->isStaff())->toBeFalse();
});
