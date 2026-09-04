<?php

use App\Models\Content;
use App\Models\PageConfig;
use App\Models\SetupState;
use App\Models\Site;
use App\Security\CrossTenantWriteException;
use App\Support\CurrentSite;

/*
 * Acceptance 10 — a mutating request naming a record outside the locked tenant is rejected.
 * The BelongsToSite write guard fires on save/delete when a tenant is locked and the row's site_id
 * differs. A no-op with no lock (jobs / lobby), mirroring SiteScope.
 */

afterEach(fn () => CurrentSite::clear());

it('rejects updating a BelongsToSite row that belongs to another tenant while one is locked', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $bContent = Content::factory()->create(['site_id' => $b->id]); // created with no lock

    CurrentSite::set($a->id);

    expect(fn () => $bContent->forceFill(['title' => 'hijacked'])->save())
        ->toThrow(CrossTenantWriteException::class);
});

it('rejects deleting another tenant\'s row while one is locked', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $bContent = Content::factory()->create(['site_id' => $b->id]);

    CurrentSite::set($a->id);

    expect(fn () => $bContent->delete())->toThrow(CrossTenantWriteException::class);
});

it('allows writing the locked tenant\'s own row', function () {
    $a = Site::factory()->create();
    $own = Content::factory()->create(['site_id' => $a->id]);

    CurrentSite::set($a->id);

    $own->forceFill(['title' => 'fine'])->save();
    expect($own->fresh()->title)->toBe('fine');
});

it('is a no-op with no tenant locked — cross-tenant writes are allowed (jobs / lobby)', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $bContent = Content::factory()->create(['site_id' => $b->id]);

    CurrentSite::clear(); // no lock

    $bContent->forceFill(['title' => 'batch job edit'])->save();
    expect($bContent->fresh()->title)->toBe('batch job edit');
});

it('guards the Setup-written models too — SetupState and PageConfig (where a mis-scoped write is invisible)', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $bState = SetupState::factory()->create(['site_id' => $b->id]);
    $bContent = Content::factory()->create(['site_id' => $b->id]);
    $bConfig = PageConfig::factory()->create(['site_id' => $b->id, 'content_id' => $bContent->id]);

    CurrentSite::set($a->id);

    expect(fn () => $bState->forceFill(['current_step' => 9])->save())->toThrow(CrossTenantWriteException::class)
        ->and(fn () => $bConfig->forceFill(['hero_variant' => 'form'])->save())->toThrow(CrossTenantWriteException::class);
});

it('rejects CREATING a row for another tenant while one is locked (explicit mismatched site_id)', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    CurrentSite::set($a->id);

    expect(fn () => SetupState::factory()->create(['site_id' => $b->id]))
        ->toThrow(CrossTenantWriteException::class);
});
