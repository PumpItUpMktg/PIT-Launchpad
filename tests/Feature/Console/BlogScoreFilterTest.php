<?php

use App\Enums\ContentStatus;
use App\Filament\Console\Pages\BlogCandidates;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    Filament::setCurrentPanel('console');
    $this->actingAs(User::factory()->create());
});

function scoredCandidate(Site $site, string $title, float $score): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id,
        'status' => ContentStatus::Scored,
        'title' => $title,
        'body' => '<p>Body.</p>',
        'relevance_score' => $score,
    ]);
}

it('filters candidate cards by relevance-score band', function () {
    $site = Site::factory()->create();
    scoredCandidate($site, 'High pick', 0.82);
    scoredCandidate($site, 'Mid pick', 0.60);
    scoredCandidate($site, 'Low pick', 0.35);

    $page = new BlogCandidates;
    $page->siteId = $site->id;

    // No band → all three.
    expect(collect($page->getCandidatesProperty())->pluck('title'))
        ->toContain('High pick', 'Mid pick', 'Low pick');

    // 75+ → only the high one.
    $page->scoreBand = 'high';
    expect(collect($page->getCandidatesProperty())->pluck('title')->all())->toBe(['High pick']);

    // 50–75 → only the mid one (75 excluded from this band).
    $page->scoreBand = 'mid';
    expect(collect($page->getCandidatesProperty())->pluck('title')->all())->toBe(['Mid pick']);

    // Under 50 → only the low one.
    $page->scoreBand = 'low';
    expect(collect($page->getCandidatesProperty())->pluck('title')->all())->toBe(['Low pick']);
});

it('excludes unscored cards while a score band is active', function () {
    $site = Site::factory()->create();
    scoredCandidate($site, 'Scored', 0.80);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Scored, 'title' => 'Unscored',
        'body' => '<p>Body.</p>', 'relevance_score' => null,
    ]);

    $page = new BlogCandidates;
    $page->siteId = $site->id;
    $page->scoreBand = 'high';

    expect(collect($page->getCandidatesProperty())->pluck('title')->all())->toBe(['Scored']);
});
