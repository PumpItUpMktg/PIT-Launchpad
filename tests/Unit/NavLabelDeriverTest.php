<?php

use App\Publishing\Chrome\NavLabelDeriver;

beforeEach(function () {
    $this->deriver = new NavLabelDeriver;
});

it('strips the hub terms from the child, singular/plural aware', function () {
    // "Sump Pumps" (hub) strips "Sump Pump" from the child → "Installation".
    expect($this->deriver->derive('Sump Pump Installation', 'Sump Pumps'))->toBe('Installation')
        ->and($this->deriver->derive('Sump Pump Repair', 'Sump Pumps'))->toBe('Repair')
        ->and($this->deriver->derive('Battery Backup', 'Backup Systems'))->toBe('Battery')
        ->and($this->deriver->derive('Water-Powered Backup', 'Backup Systems'))->toBe('Water-Powered');
});

it('falls back (null) when nothing is stripped', function () {
    expect($this->deriver->derive('Radon Mitigation', 'Sump Pumps'))->toBeNull();
});

it('falls back (null) when the result is empty or under ~3 chars', function () {
    // Child is entirely the hub's terms → nothing left.
    expect($this->deriver->derive('Sump Pump', 'Sump Pumps'))->toBeNull()
        // Strips to "Ex" (< 3 chars) → fall back.
        ->and($this->deriver->derive('Sump Pump Ex', 'Sump Pumps'))->toBeNull();
});

it('never strips a distinctive brand term, but still strips category words the brand shares with the hub', function () {
    // Brand "Sump Pump Gurus": "sump"/"pump" are shared with the hub → still strippable; "gurus" is protected.
    expect($this->deriver->derive('Sump Pump Installation', 'Sump Pumps', ['Sump', 'Pump', 'Gurus']))->toBe('Installation')
        ->and($this->deriver->derive('Gurus Sump Pump Care', 'Sump Pumps', ['Sump', 'Pump', 'Gurus']))->toBe('Gurus Care');
});

it('drops a colliding label for BOTH siblings, not just the second', function () {
    // Both "Battery Backup" and "Battery System" reduce to "Battery" under "Backup Systems".
    $out = $this->deriver->deriveGroup([
        'a' => 'Battery Backup',
        'b' => 'Battery System',
        'c' => 'Water-Powered Backup',
    ], 'Backup Systems');

    expect($out['a'])->toBeNull()          // collided → fell back
        ->and($out['b'])->toBeNull()       // collided → fell back (not only the 2nd)
        ->and($out['c'])->toBe('Water-Powered');   // unique → kept
});

it('derives a whole group with mixed keep/fallback outcomes', function () {
    $out = $this->deriver->deriveGroup([
        'install' => 'Sump Pump Installation',
        'repair' => 'Sump Pump Repair',
        'radon' => 'Radon Mitigation',      // unrelated → title
    ], 'Sump Pumps');

    expect($out)->toBe([
        'install' => 'Installation',
        'repair' => 'Repair',
        'radon' => null,
    ]);
});
