<?php

namespace App\Http\Controllers\Reviews;

use App\Enums\ReviewSource;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\ReviewRequest;
use App\Models\Site;
use App\Reviews\Intake\CompletedJob;
use App\Reviews\Requests\ReviewTokens;
use App\Reviews\ReviewSettings;
use App\Support\CurrentSite;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The public, no-auth review submission surface (§6). Reached only by a single-use signed token; the token
 * carries the tenant (resolved without the site scope, then bound as the current site). A live token shows the
 * form; a spent or expired one shows a friendly "expired" page. On submit the review lands as `pending`, the
 * request is marked submitted, and the token is retired — one and done. Rating shows regardless of value; there
 * is no rating-gated branching (Google policy).
 */
class ReviewSubmissionController extends Controller
{
    public function show(ReviewTokens $tokens, string $token): View
    {
        $request = $tokens->find($token);
        if (! $this->isLive($request)) {
            return view('reviews.expired');
        }
        CurrentSite::set((string) $request->site_id);
        $request->forceFill(['opened_at' => $request->opened_at ?? now()])->save();

        return view('reviews.submit', [
            'token' => $token,
            'brand' => $this->brand($request),
        ]);
    }

    public function submit(Request $httpRequest, ReviewTokens $tokens, ReviewSettings $settings, string $token): RedirectResponse|View
    {
        $request = $tokens->find($token);
        if (! $this->isLive($request)) {
            return view('reviews.expired');
        }
        CurrentSite::set((string) $request->site_id);
        $site = Site::query()->withoutGlobalScopes()->findOrFail($request->site_id);

        $validated = $httpRequest->validate([
            'rating' => ['required', 'integer', Rule::in([1, 2, 3, 4, 5])],
            'body' => ['required', 'string', 'min:'.$settings->bodyMinLength($site)],
            'customer_email' => ['nullable', 'email'],   // optional contact correction
            'customer_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $job = CompletedJob::fromArray($request->payload);

        $review = new Review([
            'site_id' => $job->siteId,
            'location_id' => $job->locationId,
            'external_ref' => $job->externalRef,
            'source' => ReviewSource::FirstParty,
            'status' => ReviewStatus::Pending,
            'rating' => (int) $validated['rating'],
            'body' => trim((string) $validated['body']),
            'customer_name' => $job->displayName(),
            'customer_email' => $validated['customer_email'] ?? ($job->customerEmail !== '' ? $job->customerEmail : null),
            'customer_phone' => $validated['customer_phone'] ?? $job->customerPhone,
            'service_address' => $job->serviceAddress !== '' ? $job->serviceAddress : null,
            'reviewed_at' => now(),
            'submitted_at' => now(),
            'needs_location' => $job->locationId === null,
        ]);
        $review->save();

        $serviceIds = array_slice($job->serviceIds, 0, Review::MAX_SERVICES);
        if ($serviceIds !== []) {
            $review->services()->attach($serviceIds);
        }

        $request->forceFill(['review_id' => $review->id, 'submitted_at' => now()])->save();

        return redirect()->route('reviews.thanks', ['token' => $token]);
    }

    public function thanks(ReviewTokens $tokens, string $token): View
    {
        $request = $tokens->find($token);

        return view('reviews.thanks', [
            'brand' => $request !== null ? $this->brand($request) : 'us',
        ]);
    }

    private function isLive(?ReviewRequest $request): bool
    {
        return $request !== null
            && $request->submitted_at === null
            && ($request->expires_at === null || $request->expires_at->isFuture());
    }

    private function brand(ReviewRequest $request): string
    {
        return $request->site?->account?->branding()['name'] ?? 'us';
    }
}
