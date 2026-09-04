<?php

use App\Enums\EditReason;
use App\Enums\SetupStep;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Overview;
use App\Filament\Resources\ContentEditResource;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\ContentEdit;
use App\Models\Interview;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Acceptance 9 — a tenant-owned model queried without an explicit filter returns only the locked
 * tenant's rows, INCLUDING the seven models that adopted BelongsToSite in 2c. The two documented
 * cross-tenant surfaces (Overview triage, ContentEdit read-across log) still span tenants by dropping
 * the scope explicitly.
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

it('the Overview triage board still reads EVERY tenant\'s SetupState under a lock', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $stepCount = count(SetupStep::setupSteps());
    $a = Site::factory()->create(['brand_name' => 'Alpha', 'status' => SiteStatus::Onboarding]);
    $b = Site::factory()->create(['brand_name' => 'Beta', 'status' => SiteStatus::Onboarding]);
    SetupState::factory()->create(['site_id' => $a->id, 'current_step' => 1]);
    SetupState::factory()->create(['site_id' => $b->id, 'current_step' => $stepCount]); // Beta near-done

    CurrentSite::set($a->id); // operator locked into Alpha

    $cards = collect(Livewire::test(Overview::class)->instance()->sites);
    $bCard = $cards->firstWhere('id', $b->id);

    // With the scope wrongly applied, Beta's state would be filtered out → step defaults to 1 → low pct.
    // The fix drops the scope, so Beta's real (last-step) state drives pct to 100.
    expect($bCard)->not->toBeNull()
        ->and($bCard['pct'])->toBe(100);
});

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
