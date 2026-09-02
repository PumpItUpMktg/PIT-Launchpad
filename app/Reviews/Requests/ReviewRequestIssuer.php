<?php

namespace App\Reviews\Requests;

use App\Mail\ReviewRequestMail;
use App\Models\ReviewRequest;
use App\Models\Scopes\SiteScope;
use App\Reviews\Intake\CompletedJob;
use Illuminate\Support\Facades\Mail;

/**
 * Turns a {@see CompletedJob} into an outbound review request (§6): a `review_requests` row with a single-use
 * hashed token + expiry and a snapshot of the payload, and a QUEUED request email — nothing is sent inline in a
 * web request. Idempotent by (site_id, external_ref): a redelivering upstream that hands the same completed job
 * twice gets the existing request back, never a duplicate (the DB partial-unique index is the hard guard).
 */
final class ReviewRequestIssuer
{
    public function __construct(private readonly ReviewTokens $tokens) {}

    public function issue(CompletedJob $job): ReviewRequest
    {
        // Idempotent redelivery guard (mirrors the DB unique on (site_id, external_ref)).
        if ($job->externalRef !== null) {
            $existing = ReviewRequest::query()->withoutGlobalScope(SiteScope::class)
                ->where('site_id', $job->siteId)->where('external_ref', $job->externalRef)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        [$plain, $hash] = $this->tokens->generate();

        $request = new ReviewRequest([
            'site_id' => $job->siteId,
            'external_ref' => $job->externalRef,
            'token' => $hash,
            'payload' => $job->toArray(),
            'sent_at' => now(),
            'expires_at' => now()->addDays((int) config('reviews.request_ttl_days', 30)),
            'reminder_count' => 0,
        ]);
        $request->save();

        if ($job->customerEmail !== '') {
            Mail::to($job->customerEmail)->queue(new ReviewRequestMail((string) $request->id, $plain));
        }

        return $request;
    }
}
