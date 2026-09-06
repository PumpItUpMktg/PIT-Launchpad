<?php

use App\ContentEngine\BlogQueue\ManualCandidateIntake;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

it('creates a manual candidate — topical, local when a town is given', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    $c = app(ManualCandidateIntake::class)->create($site, 'polk high-water-table job', $silo->id, 'Polk');

    expect($c)->not->toBeNull()
        ->and($c->status)->toBe(ContentStatus::Candidate)
        ->and($c->kind)->toBe(ContentKind::Post)
        ->and($c->source_name)->toBe('manual')
        ->and($c->silo_id)->toBe($silo->id)
        ->and($c->matched_silo_id)->toBe($silo->id)
        ->and($c->title)->toBe('Polk high-water-table job') // ucfirst, not from a keyword
        ->and($c->meta['shelf_life'])->toBe('topical')
        ->and($c->meta['scope'])->toBe('local')
        ->and($c->meta['manual'])->toBeTrue()
        ->and($c->meta['manual_town'])->toBe('Polk');
});

it('is general scope with no town', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    $c = app(ManualCandidateIntake::class)->create($site, 'A general water-heater explainer', $silo->id, null);

    expect($c->meta['scope'])->toBe('general')
        ->and($c->meta)->not->toHaveKey('manual_town');
});

it('returns null for an empty title or a silo outside the site', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $foreign = Silo::factory()->create(['site_id' => Site::factory()->create()->id]);

    expect(app(ManualCandidateIntake::class)->create($site, '   ', $silo->id))->toBeNull()
        ->and(app(ManualCandidateIntake::class)->create($site, 'Fine title', $foreign->id))->toBeNull();

    // Neither attempt created a row.
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
