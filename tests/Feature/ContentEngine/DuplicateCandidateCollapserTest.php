<?php

use App\ContentEngine\DuplicateCandidateCollapser;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

function dupCandidate(Site $site, array $attrs): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
    ], $attrs));
}

it('collapses same-title duplicates, keeping the earliest and rejecting the rest', function () {
    $site = Site::factory()->create();
    $title = 'Philadelphia Caulking Company Educates On Basement Waterproofing';

    $keep = dupCandidate($site, ['title' => $title, 'slug' => 'a', 'created_at' => now()->subDays(2)]);
    $d1 = dupCandidate($site, ['title' => $title, 'slug' => 'b', 'created_at' => now()->subDay()]);
    $d2 = dupCandidate($site, ['title' => $title, 'slug' => 'c', 'created_at' => now()]);
    $other = dupCandidate($site, ['title' => 'A different story entirely', 'slug' => 'd']);

    $r = app(DuplicateCandidateCollapser::class)->collapse($site);

    expect($r['groups'])->toBe(1)
        ->and($r['duplicates'])->toBe(2)
        ->and($keep->fresh()->status)->toBe(ContentStatus::Candidate)     // earliest survives
        ->and($d1->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($d1->fresh()->reject_reason)->toBe('duplicate')
        ->and($d2->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($other->fresh()->status)->toBe(ContentStatus::Candidate);    // untouched
});

it('groups by external_id even when titles differ', function () {
    $site = Site::factory()->create();
    $ext = 'googlenews:'.sha1('same-article-link');

    $keep = dupCandidate($site, ['title' => 'Headline as outlet A wrote it', 'slug' => 'a', 'external_id' => $ext, 'created_at' => now()->subDay()]);
    $dup = dupCandidate($site, ['title' => 'Headline as a later run reworded it', 'slug' => 'b', 'external_id' => $ext, 'created_at' => now()]);

    $r = app(DuplicateCandidateCollapser::class)->collapse($site);

    expect($r['duplicates'])->toBe(1)
        ->and($keep->fresh()->status)->toBe(ContentStatus::Candidate)
        ->and($dup->fresh()->status)->toBe(ContentStatus::Rejected);
});

it('is idempotent — a second run finds no duplicates', function () {
    $site = Site::factory()->create();
    $title = 'Repeated story';
    dupCandidate($site, ['title' => $title, 'slug' => 'a', 'created_at' => now()->subDay()]);
    dupCandidate($site, ['title' => $title, 'slug' => 'b', 'created_at' => now()]);

    app(DuplicateCandidateCollapser::class)->collapse($site);
    $second = app(DuplicateCandidateCollapser::class)->collapse($site);

    expect($second['duplicates'])->toBe(0)
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('status', ContentStatus::Candidate->value)->count())->toBe(1);
});

it('the dedupe-candidates command runs for a site', function () {
    $site = Site::factory()->create();
    $title = 'Repeated story';
    dupCandidate($site, ['title' => $title, 'slug' => 'a', 'created_at' => now()->subDay()]);
    dupCandidate($site, ['title' => $title, 'slug' => 'b', 'created_at' => now()]);

    $this->artisan('launchpad:dedupe-candidates', ['site' => $site->id])
        ->expectsOutputToContain('1 duplicate candidate(s) rejected')
        ->assertSuccessful();
});
