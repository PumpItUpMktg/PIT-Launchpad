<?php

use App\Enums\EditReason;
use App\Filament\Resources\ContentEditResource;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\ContentEdit;
use App\Models\Interview;
use App\Models\SetupState;
use App\Models\Site;
use App\Support\CurrentSite;

/*
 * Acceptance 9 — a tenant-owned model queried without an explicit filter returns only the locked
 * tenant's rows, INCLUDING the seven models that adopted BelongsToSite in 2c. (The cross-tenant Overview
 * triage is retired into the Lobby; the ContentEdit read-across log is scoped to the lock in step D.)
 */

afterEach(fn () => CurrentSite::clear());

it('scopes the seven to the locked tenant when queried without a filter (acceptance 9)', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    // Seed both tenants with NO lock (the guard would block a cross-tenant create).
    SetupState::factory()->create(['site_id' => $a->id]);
    SetupState::factory()->create(['site_id' => $b->id]);
    Interview::factory()->create(['site_id' => $a->id]);
    Interview::factory()->create(['site_id' => $b->id]);
    BuildPage::factory()->create(['site_id' => $a->id]);
    BuildPage::factory()->create(['site_id' => $b->id]);

    CurrentSite::set($a->id);

    expect(SetupState::query()->count())->toBe(1)
        ->and(Interview::query()->count())->toBe(1)
        ->and(BuildPage::query()->count())->toBe(1)
        ->and(SetupState::query()->sole()->site_id)->toBe($a->id);
});

it('reads across tenants in the lobby (no lock) — the operator cross-tenant view still works', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    SetupState::factory()->create(['site_id' => $a->id]);
    SetupState::factory()->create(['site_id' => $b->id]);

    CurrentSite::clear(); // lobby / no lock

    expect(SetupState::query()->count())->toBe(2);
});

it('auto-fills site_id from the locked tenant on create', function () {
    $a = Site::factory()->create();
    CurrentSite::set($a->id);

    $state = SetupState::query()->create(['current_step' => 1]); // no site_id given

    expect($state->site_id)->toBe($a->id);
});

// REMOVED (tenant-lock remediation, rule 3): "the Overview triage board still reads EVERY tenant's
// SetupState under a lock" asserted a cross-tenant read under a lock — it codified the breach as intended.
// The cross-tenant Overview is deleted (its triage function is the Lobby's, which reads the permitted set,
// not "every tenant"). The SetupState global-scope opt-out remains covered by the model + Lobby tests.

it('the ContentEdit read-across badge counts corrections across ALL tenants under a lock', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $ca = Content::factory()->create(['site_id' => $a->id]);
    $cb = Content::factory()->create(['site_id' => $b->id]);
    // Created with no lock (guard would block the cross-tenant one otherwise).
    ContentEdit::create(['site_id' => $a->id, 'content_id' => $ca->id, 'field' => 'title', 'reason' => EditReason::OffBase, 'original' => 'x', 'edited' => 'y']);
    ContentEdit::create(['site_id' => $b->id, 'content_id' => $cb->id, 'field' => 'title', 'reason' => EditReason::OffBase, 'original' => 'x', 'edited' => 'y']);

    CurrentSite::set($a->id);

    // The nav badge is the operator-wide signal log — it must span tenants, not collapse to Alpha.
    expect(ContentEditResource::getNavigationBadge())->toBe('2');
});
