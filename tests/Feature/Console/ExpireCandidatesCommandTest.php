<?php

use App\ContentEngine\BlogQueue\ManualCandidateIntake;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;

/** A candidate Content with a shelf_life + an article publish date N days ago (null date → uses created_at). */
function expiryCandidate(Site $site, string $shelfLife, ?int $publishedDaysAgo, array $extra = []): Content
{
    $meta = ['shelf_life' => $shelfLife];
    if ($publishedDaysAgo !== null) {
        $meta['source_published_at'] = now()->subDays($publishedDaysAgo)->toDateString();
    }

    return Content::factory()->post()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Post,
        'status' => ContentStatus::Candidate,
        'body' => null,
        'meta' => $meta,
    ], $extra));
}

it('report-only names stale topical candidates and changes nothing', function () {
    $site = Site::factory()->create();
    $stale = expiryCandidate($site, 'topical', 40);

    $this->artisan('launchpad:expire-candidates')
        ->assertSuccessful()
        ->expectsOutputToContain('report-only')
        ->expectsOutputToContain('Would reject 1 stale topical candidate');

    expect($stale->fresh()->status)->toBe(ContentStatus::Candidate); // untouched
});

it('--execute rejects a stale topical candidate with reason=expired', function () {
    $site = Site::factory()->create();
    $stale = expiryCandidate($site, 'topical', 40);

    $this->artisan('launchpad:expire-candidates --execute')->assertSuccessful();

    $fresh = $stale->fresh();
    expect($fresh->status)->toBe(ContentStatus::Rejected)
        ->and($fresh->reject_reason)->toBe('expired');
});

it('never expires an evergreen candidate, however old', function () {
    $site = Site::factory()->create();
    $evergreen = expiryCandidate($site, 'evergreen', 400);

    $this->artisan('launchpad:expire-candidates --execute')
        ->assertSuccessful()
        ->expectsOutputToContain('No stale topical candidates');

    expect($evergreen->fresh()->status)->toBe(ContentStatus::Candidate);
});

it('keeps a topical candidate still inside the freshness window', function () {
    $site = Site::factory()->create();
    $fresh = expiryCandidate($site, 'topical', 5);

    $this->artisan('launchpad:expire-candidates --execute')->assertSuccessful();

    expect($fresh->fresh()->status)->toBe(ContentStatus::Candidate);
});

it('falls back to the ingest date when the article publish date is unknown', function () {
    $site = Site::factory()->create();
    $noDate = expiryCandidate($site, 'topical', null); // no source_published_at
    $noDate->forceFill(['created_at' => now()->subDays(60)])->save();

    $this->artisan('launchpad:expire-candidates --execute')->assertSuccessful();

    expect($noDate->fresh()->status)->toBe(ContentStatus::Rejected);
});

it('never auto-rejects a drafted candidate', function () {
    $site = Site::factory()->create();
    // An in_review candidate that already carries a draft body — must be left for the operator.
    $drafted = expiryCandidate($site, 'topical', 40, ['status' => ContentStatus::InReview, 'body' => 'A written draft.']);

    $this->artisan('launchpad:expire-candidates --execute')
        ->assertSuccessful()
        ->expectsOutputToContain('No stale topical candidates');

    expect($drafted->fresh()->status)->toBe(ContentStatus::InReview);
});

it('expires a manual candidate by its created_at (no article date)', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $manual = app(ManualCandidateIntake::class)->create($site, 'Idea nobody wrote', $silo->id, null);
    $manual->forceFill(['created_at' => now()->subDays(45)])->save();

    $this->artisan('launchpad:expire-candidates --execute')->assertSuccessful();

    $fresh = $manual->fresh();
    expect($fresh->status)->toBe(ContentStatus::Rejected)
        ->and($fresh->reject_reason)->toBe('expired');
});

it('honors a custom --days window', function () {
    $site = Site::factory()->create();
    $c = expiryCandidate($site, 'topical', 10);

    // 10-day-old article is fresh at 30d, stale at 7d.
    $this->artisan('launchpad:expire-candidates --days=7 --execute')->assertSuccessful();
    expect($c->fresh()->status)->toBe(ContentStatus::Rejected);
});
