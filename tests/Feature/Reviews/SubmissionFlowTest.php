<?php

use App\Enums\ReviewStatus;
use App\Mail\ReviewRequestMail;
use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewRequest;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Reviews\Intake\CompletedJob;
use App\Reviews\Requests\ReviewRequestIssuer;
use App\Reviews\Requests\ReviewTokens;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

function completedJob(Site $site, ?string $locationId = null, array $serviceIds = [], ?string $externalRef = 'job-1'): CompletedJob
{
    return new CompletedJob(
        siteId: (string) $site->id,
        externalRef: $externalRef,
        customerFirstName: 'John', customerLastInitial: 'D',
        customerEmail: 'john@example.com', customerPhone: null,
        serviceAddress: '123 Main St, Trooper, PA', locationId: $locationId,
        serviceIds: $serviceIds, completedAt: Carbon::now(),
    );
}

test('issuing a request stores a hashed token + expiry and queues the email', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    $request = app(ReviewRequestIssuer::class)->issue(completedJob($site));

    expect(strlen($request->token))->toBe(64)          // sha-256 hex, not the plaintext
        ->and($request->expires_at)->not->toBeNull()
        ->and($request->payload['customer_email'])->toBe('john@example.com');
    Mail::assertQueued(ReviewRequestMail::class);
});

test('issuing is idempotent per (site, external_ref) — a redelivery does not double-issue', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    $first = app(ReviewRequestIssuer::class)->issue(completedJob($site, externalRef: 'job-9'));
    $second = app(ReviewRequestIssuer::class)->issue(completedJob($site, externalRef: 'job-9'));

    expect($second->id)->toBe($first->id)
        ->and(ReviewRequest::query()->count())->toBe(1);
    Mail::assertQueued(ReviewRequestMail::class, 1); // only the first send
});

test('a live token shows the form; submitting lands a pending review and retires the token', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create();
    $service = Service::factory()->for($site)->create();

    [$plain, $hash] = app(ReviewTokens::class)->generate();
    $request = ReviewRequest::factory()->for($site)->create([
        'token' => $hash, 'submitted_at' => null, 'expires_at' => now()->addDays(30),
        'payload' => completedJob($site, $location->id, [$service->id])->toArray(),
    ]);

    $this->get(route('reviews.show', $plain))->assertOk()->assertSee('How did we do?');

    $this->post(route('reviews.submit', $plain), [
        'rating' => 5, 'body' => 'Great work — professional, clean, and on time. Highly recommend.',
    ])->assertRedirect(route('reviews.thanks', $plain));

    $review = Review::query()->withoutGlobalScope(SiteScope::class)->first();
    expect($review->status)->toBe(ReviewStatus::Pending)
        ->and($review->rating)->toBe(5)
        ->and($review->location_id)->toBe((string) $location->id)
        ->and($review->customer_name)->toBe('John D.')
        ->and($review->needs_location)->toBeFalse()
        ->and($review->services()->pluck('services.id')->all())->toBe([(string) $service->id]);

    // Single-use: the request is submitted and the link no longer works.
    expect($request->fresh()->submitted_at)->not->toBeNull()
        ->and($request->fresh()->review_id)->toBe((string) $review->id);
    $this->get(route('reviews.show', $plain))->assertOk()->assertSee('no longer active');
});

test('a review with no resolved location lands needs_location', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    [$plain, $hash] = app(ReviewTokens::class)->generate();
    ReviewRequest::factory()->for($site)->create([
        'token' => $hash, 'expires_at' => now()->addDays(30),
        'payload' => completedJob($site, locationId: null)->toArray(),
    ]);

    $this->post(route('reviews.submit', $plain), ['rating' => 4, 'body' => str_repeat('good ', 6)])->assertRedirect();

    expect(Review::query()->withoutGlobalScope(SiteScope::class)->first()->needs_location)->toBeTrue();
});

test('the body min length is enforced (per-tenant, config default)', function (): void {
    $site = Site::factory()->create(['review_body_min_length' => 40]);
    CurrentSite::set($site->id);
    [$plain, $hash] = app(ReviewTokens::class)->generate();
    ReviewRequest::factory()->for($site)->create(['token' => $hash, 'expires_at' => now()->addDays(30), 'payload' => completedJob($site)->toArray()]);

    $this->from(route('reviews.show', $plain))
        ->post(route('reviews.submit', $plain), ['rating' => 5, 'body' => 'too short'])
        ->assertRedirect(route('reviews.show', $plain))
        ->assertSessionHasErrors('body');

    expect(Review::query()->withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

test('an expired token shows the expired page, never the form', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    [$plain, $hash] = app(ReviewTokens::class)->generate();
    ReviewRequest::factory()->for($site)->create(['token' => $hash, 'expires_at' => now()->subDay(), 'payload' => completedJob($site)->toArray()]);

    $this->get(route('reviews.show', $plain))->assertOk()->assertSee('no longer active');
});

test('the day-3 reminder fires, rotates the token, and bumps the count', function (): void {
    Mail::fake();
    $site = Site::factory()->create(['review_reminders_enabled' => true]);
    CurrentSite::set($site->id);
    $request = ReviewRequest::factory()->for($site)->create([
        'sent_at' => now()->subDays(4), 'submitted_at' => null, 'reminder_count' => 0,
        'expires_at' => now()->addDays(30), 'payload' => ['customer_email' => 'c@example.com'],
    ]);
    $before = $request->token;

    $this->artisan('launchpad:send-review-reminders')->assertSuccessful();

    Mail::assertQueued(ReviewRequestMail::class, 1);
    $request->refresh();
    expect($request->reminder_count)->toBe(1)
        ->and($request->token)->not->toBe($before); // fresh single-use link
});

test('the reminder is not due before its day and never exceeds the cap', function (): void {
    Mail::fake();
    $site = Site::factory()->create(['review_reminders_enabled' => true]);
    CurrentSite::set($site->id);
    // Day-2: the day-3 reminder isn't due yet.
    ReviewRequest::factory()->for($site)->create(['sent_at' => now()->subDays(2), 'reminder_count' => 0, 'expires_at' => now()->addDays(30), 'payload' => ['customer_email' => 'a@x.com']]);
    // Already at the cap.
    ReviewRequest::factory()->for($site)->create(['sent_at' => now()->subDays(20), 'reminder_count' => 2, 'expires_at' => now()->addDays(30), 'payload' => ['customer_email' => 'b@x.com']]);

    $this->artisan('launchpad:send-review-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('a reminders-disabled tenant is skipped', function (): void {
    Mail::fake();
    $off = Site::factory()->create(['review_reminders_enabled' => false]);
    CurrentSite::set($off->id);
    ReviewRequest::factory()->for($off)->create(['sent_at' => now()->subDays(4), 'reminder_count' => 0, 'expires_at' => now()->addDays(30), 'payload' => ['customer_email' => 'd@example.com']]);

    $this->artisan('launchpad:send-review-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});
