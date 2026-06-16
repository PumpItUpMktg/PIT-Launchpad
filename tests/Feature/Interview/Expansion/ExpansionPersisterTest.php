<?php

use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Interview\Expansion\ExpansionPersister;
use App\Interview\Expansion\ExpansionResult;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Tests\Support\ExpansionFixture;

function spokesFor(Site $site)
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
}

test('it persists the candidate tree as a draft blueprint: pillars, spokes, and fringe', function () {
    $site = Site::factory()->create();
    $result = ExpansionResult::fromArray(ExpansionFixture::tree());

    $blueprint = app(ExpansionPersister::class)->persist($site, $result);

    // 4 pillars + 10 spokes + 2 fringe = 16 rows.
    expect(spokesFor($site)->count())->toBe(16)
        ->and((clone $blueprint)->spokes()->where('is_pillar', true)->count())->toBe(4);

    $pillar = spokesFor($site)->where('is_pillar', true)->where('name', 'Sump Pumps')->first();
    expect($pillar->tag)->toBe(SpokeTag::Core)
        ->and($pillar->status)->toBe(SpokeStatus::Candidate)
        ->and($pillar->head_keyword)->toBe('sump pump');

    // every candidate row is pre-prune + volume-pending
    expect(spokesFor($site)->where('status', '!=', SpokeStatus::Candidate->value)->count())->toBe(0)
        ->and(spokesFor($site)->whereNotNull('volume')->count())->toBe(0);
});

test('fringe rows are tagged fringe with the sibling-brand handoff hint, grouped out of lane', function () {
    $site = Site::factory()->create();
    app(ExpansionPersister::class)->persist($site, ExpansionResult::fromArray(ExpansionFixture::tree()));

    $mold = spokesFor($site)->where('name', 'Mold Remediation')->first();
    expect($mold->tag)->toBe(SpokeTag::Fringe)
        ->and($mold->sibling_brand)->toBe('Trusted Mold')
        ->and($mold->silo)->toBe('Out of Lane')
        ->and($mold->is_pillar)->toBeFalse();
});

test('re-expansion replaces the prior candidate set on the one blueprint', function () {
    $site = Site::factory()->create();
    $persister = app(ExpansionPersister::class);

    $persister->persist($site, ExpansionResult::fromArray(ExpansionFixture::tree()));
    $persister->persist($site, ExpansionResult::fromArray(ExpansionFixture::tree()));

    expect(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1)
        ->and(spokesFor($site)->count())->toBe(16); // not doubled
});
