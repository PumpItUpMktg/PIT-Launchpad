<?php

use App\Mail\ReviewThankYouMail;
use App\Models\Location;
use App\Models\ReviewRequest;
use App\Models\Site;
use App\Reviews\GoogleReviewCta;
use App\Reviews\Intake\CompletedJob;
use App\Reviews\Requests\ReviewTokens;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Mail;

/** A submittable request whose payload points at $location, returns the plaintext token. */
function ctaRequest(Site $site, ?Location $location): string
{
    [$plain, $hash] = app(ReviewTokens::class)->generate();
    $payload = (new CompletedJob(
        siteId: (string) $site->id, externalRef: null,
        customerFirstName: 'Ann', customerLastInitial: 'B',
        customerEmail: 'ann@example.com', customerPhone: null,
        serviceAddress: '1 St', locationId: $location?->id, serviceIds: [], completedAt: now(),
    ))->toArray();
    ReviewRequest::factory()->for($site)->create(['token' => $hash, 'expires_at' => now()->addDays(30), 'payload' => $payload]);

    return $plain;
}

test('the CTA builds a place-id deep link, and is null without a place_id', function (): void {
    $cta = app(GoogleReviewCta::class);
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    expect($cta->urlFor(Location::factory()->for($site)->create(['place_id' => 'ChIJ_abc123'])))
        ->toBe('https://search.google.com/local/writereview?placeid=ChIJ_abc123')
        ->and($cta->urlFor(Location::factory()->for($site)->create(['place_id' => null])))->toBeNull()
        ->and($cta->urlFor(null))->toBeNull();
});

test('the Google CTA shows on the thank-you screen at every rating (no rating-gating)', function (int $rating): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create(['place_id' => 'ChIJxyz']);
    $token = ctaRequest($site, $location);

    $this->post(route('reviews.submit', $token), ['rating' => $rating, 'body' => str_repeat('great ', 6)])
        ->assertRedirect(route('reviews.thanks', $token));

    $this->get(route('reviews.thanks', $token))
        ->assertOk()
        ->assertSee('writereview?placeid=ChIJxyz', escape: false);
})->with([1, 5]); // a 1-star and a 5-star both get the CTA

test('the CTA is omitted when the location has no place_id (no generic fallback)', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create(['place_id' => null]);
    $token = ctaRequest($site, $location);

    $this->post(route('reviews.submit', $token), ['rating' => 5, 'body' => str_repeat('great ', 6)])->assertRedirect();

    $this->get(route('reviews.thanks', $token))->assertOk()->assertDontSee('writereview');
});

test('a confirmation email is queued on submit', function (): void {
    Mail::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $token = ctaRequest($site, Location::factory()->for($site)->create(['place_id' => 'ChIJq']));

    $this->post(route('reviews.submit', $token), ['rating' => 5, 'body' => str_repeat('great ', 6)])->assertRedirect();

    Mail::assertQueued(ReviewThankYouMail::class);
});
