<?php

use App\Enums\ContentStatus;
use App\Enums\ProofType;
use App\Models\Market;
use App\Models\ProofItem;
use App\Publishing\PublishContentService;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublishHarness;

function reviewEndpoint(): void
{
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 88, 'status' => 'publish', 'skipped' => false], 200)]);
}

function marketReview(string $siteId, ?string $marketId): ProofItem
{
    $review = ProofItem::factory()->create(['site_id' => $siteId, 'type' => ProofType::Testimonial, 'is_substantiated' => true]);
    if ($marketId !== null) {
        $review->markets()->attach($marketId);
    }

    return $review;
}

test('a service page publishes WITHOUT any reviews (no review gate)', function () {
    PublishHarness::fakeAdapters();
    reviewEndpoint();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site); // service page, zero reviews

    $result = app(PublishContentService::class)->publish($content);

    expect($result->isPublished())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::Published);
});

test('a location page with no market_id fails closed (location.market_missing) and never publishes', function () {
    PublishHarness::fakeAdapters();
    reviewEndpoint();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedLocationPage($site, marketId: null);

    $result = app(PublishContentService::class)->publish($content);

    expect($result->isPublished())->toBeFalse()
        ->and($result->message)->toContain('location.market_missing')
        ->and($content->fresh()->status)->toBe(ContentStatus::InReview); // parked, not live

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/launchpad/v1/content'));
});

test('a location page with a market but no reviews is blocked (review required)', function () {
    PublishHarness::fakeAdapters();
    reviewEndpoint();
    $site = PublishHarness::site();
    $market = Market::factory()->create(['site_id' => $site->id]);
    $content = PublishHarness::approvedLocationPage($site, $market->id);

    $result = app(PublishContentService::class)->publish($content);

    expect($result->isPublished())->toBeFalse()
        ->and($content->fresh()->status)->toBe(ContentStatus::InReview);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/launchpad/v1/content'));
});

test('a location page publishes when its market has a market-tagged review', function () {
    PublishHarness::fakeAdapters();
    reviewEndpoint();
    $site = PublishHarness::site();
    $market = Market::factory()->create(['site_id' => $site->id]);
    $content = PublishHarness::approvedLocationPage($site, $market->id);
    marketReview($site->id, $market->id);

    expect(app(PublishContentService::class)->publish($content)->isPublished())->toBeTrue();
});

test('a SITE-WIDE review (no market) satisfies any location market gate', function () {
    PublishHarness::fakeAdapters();
    reviewEndpoint();
    $site = PublishHarness::site();
    $market = Market::factory()->create(['site_id' => $site->id]);
    $content = PublishHarness::approvedLocationPage($site, $market->id);
    marketReview($site->id, null); // site-wide, attached to no market

    expect(app(PublishContentService::class)->publish($content)->isPublished())->toBeTrue();
});
