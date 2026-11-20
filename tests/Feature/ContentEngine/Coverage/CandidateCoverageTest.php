<?php

use App\ContentEngine\Coverage\CandidateCoverage;
use App\ContentEngine\Coverage\CoverageDecision;
use App\Enums\ContentStatus;
use App\Enums\CoverageVerdict;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Site;

function covSite(): Site
{
    app()->instance(EmbeddingProvider::class, new MockEmbeddingProvider);

    return Site::factory()->create(['domain_url' => 'https://spg.example']);
}

function covLive(Site $site, string $title, string $slug): Content
{
    return Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'title' => $title, 'slug' => $slug, 'body' => '<p>body</p>']);
}

function covCandidate(Site $site, string $title, array $meta = [], array $extra = []): Content
{
    return Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => $title, 'body' => null, 'meta' => $meta, ...$extra]);
}

function covGsc(Site $site, string $url, int $impr, int $clicks, float $pos): void
{
    GscUrlDaily::create(['site_id' => $site->id, 'grain_hash' => hash('sha256', $url.'|'.$impr), 'date' => now()->subDays(3)->toDateString(), 'url' => $url, 'impressions' => $impr, 'clicks' => $clicks, 'ctr' => 0, 'position' => $pos]);
}

it('is covered when a live post with a near-identical title already ranks near the top and the candidate has no timely hook', function () {
    $site = covSite();
    $post = covLive($site, 'Sump Pump Maintenance Checklist for Homeowners', 'sump-pump-maintenance-checklist');
    covGsc($site, 'https://spg.example/sump-pump-maintenance-checklist/', 120, 9, 3.2);
    $candidate = covCandidate($site, 'Sump Pump Maintenance Checklist for Homeowners This Fall', ['shelf_life' => 'evergreen']);

    $decision = app(CandidateCoverage::class)->stamp($site, $candidate);

    expect($decision->verdict)->toBe(CoverageVerdict::Covered)
        ->and($decision->twinId)->toBe($post->id)
        ->and($decision->twinUrl)->toContain('sump-pump-maintenance-checklist')
        ->and($decision->position)->toBe(3.2)->and($decision->impressions)->toBe(120)->and($decision->clicks)->toBe(9)
        ->and($decision->similarity)->toBeGreaterThanOrEqual(0.6)
        ->and($decision->reason)->toContain('already ranks near the top')->toContain('position 3.2 · 120 impressions')
        ->and($decision->matchedOn)->toBe('title')->and($decision->reason)->not->toContain('matched on the candidate');

    $stamped = CoverageDecision::fromMeta($candidate->fresh()->meta['coverage']);
    expect($stamped)->not->toBeNull()->and($stamped->verdict)->toBe(CoverageVerdict::Covered)->and($stamped->twinTitle)->toBe($post->title)->and($stamped->checkedAt)->not->toBeNull();
});

it('is refresh when the twin ranks near the top but the candidate carries a topical hook, or when the twin has weak or no standing', function () {
    $site = covSite();
    covLive($site, 'Sump Pump Maintenance Checklist for Homeowners', 'sump-pump-maintenance-checklist');
    covGsc($site, 'https://spg.example/sump-pump-maintenance-checklist/', 120, 9, 3.2);

    $topical = app(CandidateCoverage::class)->decide($site, covCandidate($site, 'Sump Pump Maintenance Checklist for Homeowners This Fall', ['shelf_life' => 'topical']));
    expect($topical->verdict)->toBe(CoverageVerdict::Refresh)->and($topical->reason)->toContain('fold the new development into it');

    $site2 = covSite();
    covLive($site2, 'Sump Pump Maintenance Checklist for Homeowners', 'sump-pump-maintenance-checklist');
    covGsc($site2, 'https://spg.example/sump-pump-maintenance-checklist/', 40, 0, 24.0);
    $slipping = app(CandidateCoverage::class)->decide($site2, covCandidate($site2, 'Sump Pump Maintenance Checklist for Homeowners This Fall', ['shelf_life' => 'evergreen']));
    expect($slipping->verdict)->toBe(CoverageVerdict::Refresh)->and($slipping->reason)->toContain('refresh it with the new angle')->toContain('position 24.0');

    $site3 = covSite();
    covLive($site3, 'Sump Pump Maintenance Checklist for Homeowners', 'sump-pump-maintenance-checklist');
    $noStanding = app(CandidateCoverage::class)->decide($site3, covCandidate($site3, 'Sump Pump Maintenance Checklist for Homeowners This Fall'));
    expect($noStanding->verdict)->toBe(CoverageVerdict::Refresh)->and($noStanding->position)->toBeNull()->and($noStanding->reason)->toContain('no Search Console standing');
});

it('is author when no live post reads as the topic, and takes the funnel\'s own near-dup link as the twin when the titles alone do not pair', function () {
    $site = covSite();
    $post = covLive($site, 'Sump Pump Maintenance Checklist for Homeowners', 'sump-pump-maintenance-checklist');
    covLive($site, 'Radon Fan Installation Explained', 'radon-fan-installation');

    $author = app(CandidateCoverage::class)->decide($site, covCandidate($site, 'Omaha homeowners report sewer backups after storms'));
    expect($author->verdict)->toBe(CoverageVerdict::Author)->and($author->twinId)->toBeNull();

    $linked = covCandidate($site, 'What to check before the spring thaw', ['near_dup' => ['similarity' => 0.91]], ['near_dup_of_content_id' => $post->id]);
    $viaLink = app(CandidateCoverage::class)->decide($site, $linked);
    expect($viaLink->verdict)->toBe(CoverageVerdict::Refresh)->and($viaLink->twinId)->toBe($post->id)->and($viaLink->similarity)->toBe(0.91)
        ->and($viaLink->matchedOn)->toBe('link')->and($viaLink->reason)->toContain('near-duplicate link');

    // A site with no live posts at all: author, no lookups.
    $empty = covSite();
    expect(app(CandidateCoverage::class)->decide($empty, covCandidate($empty, 'Anything'))->verdict)->toBe(CoverageVerdict::Author);
});

it('pairs a news headline with the live post through its advisory angle when the headline alone does not read as the topic', function () {
    $site = covSite();
    $post = covLive($site, 'Sump Pump Maintenance Checklist for Homeowners', 'sump-pump-maintenance-checklist');
    covLive($site, 'Radon Fan Installation Explained', 'radon-fan-installation');

    // The headline is a news story; the angle the scorer wrote is what the post would be about.
    $news = covCandidate($site, 'Philly storms expose neglected basements across the region', ['shelf_life' => 'topical'], [
        'angle_hint' => 'Sump pump maintenance checklist for homeowners before the storm',
    ]);
    $decision = app(CandidateCoverage::class)->stamp($site, $news);

    expect($decision->verdict)->toBe(CoverageVerdict::Refresh)
        ->and($decision->twinId)->toBe($post->id)
        ->and($decision->matchedOn)->toBe('angle')
        ->and($decision->reason)->toContain('matched on the candidate\'s angle')
        ->and(CoverageDecision::fromMeta($news->fresh()->meta['coverage'])->matchedOn)->toBe('angle');

    // An angle on a different topic adds nothing: the headline decides, and here neither pairs.
    $other = covCandidate($site, 'Philly storms expose neglected basements across the region', [], ['angle_hint' => 'Why downspout extensions matter after heavy rain']);
    expect(app(CandidateCoverage::class)->decide($site, $other)->verdict)->toBe(CoverageVerdict::Author);
});

it('nearest() reports the closest live post with both scores and the text, even far below the floors', function () {
    $site = covSite();
    $post = covLive($site, 'Sump Pump Maintenance Checklist for Homeowners', 'sump-pump-maintenance-checklist');
    $far = covCandidate($site, 'Township approves new sewer bond for pump station', []);
    $near = app(CandidateCoverage::class)->nearest($site, $far);
    expect($near)->not->toBeNull()->and($near['post']->id)->toBe($post->id)->and($near['text'])->toBe('title')
        ->and($near['overlap'])->toBeLessThan(0.6)->and($near['semantic'])->toBeLessThan(0.85);
    expect(app(CandidateCoverage::class)->decide($site, $far)->verdict)->toBe(CoverageVerdict::Author);

    expect(app(CandidateCoverage::class)->nearest(covSite(), covCandidate($site, 'Anything')))->toBeNull();
});
