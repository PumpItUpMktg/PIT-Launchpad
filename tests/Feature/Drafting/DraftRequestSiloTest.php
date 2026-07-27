<?php

use App\ContentEngine\Drafting\DraftRequest;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Str;

it('keeps the candidate\'s matched silo when it still exists', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sewer']);
    $candidate = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'matched_silo_id' => $silo->id, 'title' => 'Bridgewater Sewer News',
    ]);

    expect(DraftRequest::forCandidate($candidate)->siloId)->toBe($silo->id);
});

it('drops a STALE matched silo (deleted by a rebuild) to null instead of FK-crashing the draft', function () {
    $site = Site::factory()->create();
    // A candidate routed to a silo that no longer exists — the reactive-lane FK-violation case.
    $candidate = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'matched_silo_id' => (string) Str::ulid(), 'title' => 'Middle Brook Sewer Pump Station',
    ]);

    expect(DraftRequest::forCandidate($candidate)->siloId)->toBeNull();
});

it('ignores a matched silo that belongs to another tenant (site-scoped existence check)', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $otherSilo = Silo::factory()->create(['site_id' => $otherSite->id, 'name' => 'Foreign']);
    $candidate = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'matched_silo_id' => $otherSilo->id, 'title' => 'Cross-tenant silo ref',
    ]);

    expect(DraftRequest::forCandidate($candidate)->siloId)->toBeNull();
});
