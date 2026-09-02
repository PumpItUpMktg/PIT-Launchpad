<?php

namespace App\Mail;

use App\Models\Review;
use App\Models\Scopes\SiteScope;
use App\Reviews\GoogleReviewCta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The post-submission confirmation email (§6/§7). Queued. Carries the Google review CTA deep-linked to the
 * Location that owns the job — shown at every rating, omitted when the Location has no place_id. Tiny payload:
 * the review id; brand, link, and copy resolve at send time.
 */
class ReviewThankYouMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $reviewId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Thanks for your review');
    }

    public function content(): Content
    {
        $review = Review::query()->withoutGlobalScope(SiteScope::class)->with('location', 'site.account')->find($this->reviewId);

        return new Content(markdown: 'mail.review-thanks', with: [
            'brand' => $review?->site?->account?->branding()['name'] ?? 'us',
            'googleUrl' => app(GoogleReviewCta::class)->urlFor($review?->location),
        ]);
    }
}
