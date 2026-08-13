<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A field tech's capture-app invite — the PWA link + one-time login code, white-labeled to the tenant
 * brand. Sent synchronously from the operator's onboarding action so the "emailed" confirmation is honest
 * (no queue worker required). The code is one-time and short-lived; a resend mints a fresh one.
 */
class TechCaptureInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $techName,
        public string $code,
        public string $link,
        public string $brand,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf('%s — your job capture app', $this->brand));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.tech-capture-invite', with: [
            'techName' => $this->techName,
            'code' => $this->code,
            'link' => $this->link,
            'brand' => $this->brand,
        ]);
    }
}
