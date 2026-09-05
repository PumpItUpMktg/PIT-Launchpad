<?php

use App\Enums\AuditAction;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Jobs\PublishContent;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\ProofItem;
use App\Models\Silo;
use App\Publishing\PublishContentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PublishHarness;

function fakeContentEndpoint(int $wpPostId = 123, bool $skipped = false): void
{
    Http::fake([
        '*/wp-json/launchpad/v1/content' => Http::response([
            'wp_post_id' => $wpPostId,
            'status' => 'publish',
            'skipped' => $skipped,
        ], 200),
    ]);
}

test('publishing renders, pushes the meta-blob by ULID, stores wp_post_id and audits', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 123);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    $result = app(PublishContentService::class)->publish($content, 'operator-1');

    expect($result->isPublished())->toBeTrue()
        ->and($result->wpPostId)->toBe(123);

    $fresh = $content->fresh();
    expect($fresh->status)->toBe(ContentStatus::Published)
        ->and($fresh->wp_post_id)->toBe(123)
        ->and($fresh->published_at)->not->toBeNull()
        ->and($fresh->last_publish_error)->toBeNull();

    expect(AuditLog::where('action', AuditAction::ContentPublished->value)
        ->where('target_id', $content->id)->exists())->toBeTrue();

    Http::assertSent(function ($request) use ($content) {
        return str_contains($request->url(), '/wp-json/launchpad/v1/content')
            && $request['content_id'] === $content->id
            && $request['status'] === 'published'
            && $request['slot_payload']['hero_problem'] !== ''
            && is_string($request['images']['hero_image']['url'])
            && $request['seo']['title'] === 'Water Heater Repair in Austin'; // SEO title normalized (no "| Apex")
    });
});

test('a re-publish re-sends the same ULID (idempotent) and keeps wp_post_id', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 55);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    app(PublishContentService::class)->publish($content);
    app(PublishContentService::class)->publish($content->fresh());

    expect($content->fresh()->wp_post_id)->toBe(55);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['content_id'] === $content->id);
});

test('a re-publish PRESERVES the original published_at (a repush is a modification, not a new publish)', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 55);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    app(PublishContentService::class)->publish($content);

    // Simulate a page that first went live a month ago.
    $original = now()->subMonth()->startOfDay();
    $content->fresh()->forceFill(['published_at' => $original])->save();

    app(PublishContentService::class)->publish($content->fresh());

    // published_at is unchanged (first-publish date), so the dashboard's "Published" date never resets.
    expect($content->fresh()->published_at->eq($original))->toBeTrue();
});

test('a needs_review row is NEVER published — the desync guard no-ops without mutating status', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint();

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $content->forceFill(['status' => ContentStatus::NeedsReview])->save();

    $result = app(PublishContentService::class)->publish($content);

    expect($result->isPublished())->toBeFalse()
        ->and($content->fresh()->status)->toBe(ContentStatus::NeedsReview) // untouched — review flow intact
        ->and($content->fresh()->wp_post_id)->toBeNull();

    // The hole page 196 fell through: nothing reaches WordPress.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/wp-json/launchpad/v1/content'));
});

test('candidate / scored / drafted / in_review / rejected rows are all guarded out', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site); // one row, re-stamped each pass

    foreach ([ContentStatus::Candidate, ContentStatus::Scored, ContentStatus::Drafted, ContentStatus::InReview, ContentStatus::Rejected] as $status) {
        $content->forceFill(['status' => $status])->save();

        app(PublishContentService::class)->publish($content->fresh());

        expect($content->fresh()->status)->toBe($status); // unchanged by the guard
    }

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/wp-json/launchpad/v1/content'));
});

test('a render_failed row is allowed to re-publish (retry)', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 77);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $content->forceFill(['status' => ContentStatus::RenderFailed])->save();

    $result = app(PublishContentService::class)->publish($content);

    expect($result->isPublished())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::Published);
});

test('a push failure lands the content in publish_failed with the error surfaced', function () {
    PublishHarness::fakeAdapters();
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response('', 500)]);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    $result = app(PublishContentService::class)->publish($content);

    expect($result->hasFailed())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::PublishFailed)
        ->and($content->fresh()->last_publish_error)->not->toBeNull();
});

test('a published post renders its hero and sends a featured_image (posts are not imageless)', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 210);
    $site = PublishHarness::site();

    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved,
        'slug' => 'check-your-sump-pump', 'title' => 'Check your sump pump', 'body' => '<p>Real body.</p>',
        'meta' => [
            'seo' => ['title' => 'Check your sump pump', 'meta_description' => 'Stay ready.'],
            'image_specs' => [[
                'slot' => 'hero_image', 'prompt' => 'A homeowner checking a sump pump',
                'seo_filename' => 'sump-pump-check.webp', 'alt' => 'Homeowner checking a sump pump',
            ]],
        ],
    ]);

    expect(app(PublishContentService::class)->publish($post)->isPublished())->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/launchpad/v1/content')
            && is_string($request['featured_image'] ?? null) && $request['featured_image'] !== ''
            && is_string($request['images']['hero_image']['url'] ?? null);
    });
});

test('publishing content whose silo is unmapped pushes the silo (real name) to /silo first, filling wp_category_id', function () {
    PublishHarness::fakeAdapters();
    Http::fake([
        '*/wp-json/launchpad/v1/silo' => Http::response(['silo_id' => 'x', 'wp_category_id' => 42], 200),
        '*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 200, 'status' => 'publish', 'skipped' => false], 200),
    ]);
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sewer & Water Lines', 'wp_category_id' => null]);

    // A blog post routed to the silo (no review gate on posts) with a real body to publish.
    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'silo_id' => $silo->id,
        'status' => ContentStatus::Approved, 'slug' => 'sewer-costs-rising', 'body' => '<p>Real body.</p>',
    ]);

    $result = app(PublishContentService::class)->publish($post);

    // The silo went up with its HUMAN name (not a "Silo {ulid}" placeholder) and its category id landed.
    expect($result->isPublished())->toBeTrue()
        ->and($silo->fresh()->wp_category_id)->toBe(42);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/launchpad/v1/silo') && $r['name'] === 'Sewer & Water Lines');
});

test('a silo already mapped to a category is NOT re-pushed on publish', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 201);
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drains', 'wp_category_id' => 9]);
    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'silo_id' => $silo->id,
        'status' => ContentStatus::Approved, 'slug' => 'drain-tips', 'body' => '<p>Body.</p>',
    ]);

    app(PublishContentService::class)->publish($post);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/launchpad/v1/silo'));
});

test('publishing a town page re-publishes its already-live parent hub (fresh "areas we serve" links)', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 321);
    $site = PublishHarness::site();

    // The physical location + its already-published hub page (carries the baked town-links grid).
    $location = Location::factory()->released()->create(['site_id' => $site->id]); // published location pages → released for publishing
    $hub = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $location->id, 'status' => ContentStatus::Published, 'wp_post_id' => 99, 'slug' => 'trooper',
    ]);

    // A town page nested under the hub, freshly approved and about to go live (market + tagged
    // review → the location review gate passes).
    $market = Market::factory()->create(['site_id' => $site->id]);
    $review = ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Testimonial, 'is_substantiated' => true,
    ]);
    $review->markets()->attach($market->id);
    $town = PublishHarness::approvedLocationPage($site, $market->id);
    $town->forceFill(['parent_location_id' => $location->id, 'location_id' => null, 'primary_service_id' => null])->save();

    Bus::fake();
    $result = app(PublishContentService::class)->publish($town->fresh());

    // The town went live, and its parent hub is re-published so the Areas-we-serve grid picks it up.
    expect($result->isPublished())->toBeTrue();
    Bus::assertDispatched(PublishContent::class, fn (PublishContent $job) => $job->contentId === $hub->id);
});

test('publishing the hub itself never re-triggers a hub republish (no loop)', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 100);
    $site = PublishHarness::site();

    $location = Location::factory()->released()->create(['site_id' => $site->id]); // published location pages → released for publishing
    // A real, publishable hub (market + tagged review) so the success hook actually runs — the loop
    // guard is what keeps it from re-dispatching, not a failed gate short-circuiting earlier.
    $market = Market::factory()->create(['site_id' => $site->id]);
    $review = ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Testimonial, 'is_substantiated' => true,
    ]);
    $review->markets()->attach($market->id);
    $hub = PublishHarness::approvedLocationPage($site, $market->id);
    $hub->forceFill(['location_id' => $location->id, 'parent_location_id' => null])->save();

    Bus::fake();
    $result = app(PublishContentService::class)->publish($hub->fresh());

    expect($result->isPublished())->toBeTrue();
    Bus::assertNotDispatched(PublishContent::class);
});

it('is unique per content id — the dedupe guard that stops re-push waves from stacking', function () {
    $job = new PublishContent('01JQZY8Z0EXAMPLECONTENTULID0');

    // ShouldBeUnique + uniqueId = the content ULID: a second dispatch for a page that already has a
    // publish job queued/in flight is dropped by the framework. uniqueFor is a generous safety expiry.
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('01JQZY8Z0EXAMPLECONTENTULID0')
        ->and($job->uniqueFor)->toBeGreaterThanOrEqual(300);
});

it('drops a duplicate publish dispatch for the same page, but not for a different page', function () {
    Queue::fake();

    // Two dispatches for the SAME page → the second is deduped away (one job).
    PublishContent::dispatch('01JQZY8Z0SAMEPAGEULID000000');
    PublishContent::dispatch('01JQZY8Z0SAMEPAGEULID000000');
    Queue::assertPushed(PublishContent::class, 1);

    // A DIFFERENT page still gets its own job.
    PublishContent::dispatch('01JQZY8Z0OTHERPAGEULID00000');
    Queue::assertPushed(PublishContent::class, 2);
});
