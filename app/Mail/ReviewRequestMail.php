<?php

namespace App\Mail;

use App\Models\ReviewRequest;
use App\Models\Scopes\SiteScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The review-request email (§6). Queued — never sent inline in a web request (this app has an origin 504
 * problem; nothing outbound blocks a request). Tiny payload: the request id + the plaintext token; the brand,
 * link, and copy resolve at send time on the worker. White-labeled to the tenant's Account brand.
 */
class ReviewRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $requestId,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'How was your experience with '.$this->brand().'?');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.review-request', with: [
            'brand' => $this->brand(),
            'url' => route('reviews.show', ['token' => $this->token]),
        ]);
    }

    private function brand(): string
    {
        $request = ReviewRequest::query()->withoutGlobalScope(SiteScope::class)->find($this->requestId);
        $account = $request?->site?->account;

        return $account?->branding()['name'] ?? 'us';
    }
}
