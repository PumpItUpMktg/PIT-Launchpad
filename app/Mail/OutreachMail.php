<?php

namespace App\Mail;

use App\Models\OutreachEmail;
use App\Models\Site;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One reviewed outreach email (§ Links): plain text, from the tenant's own address with replies to the same
 * inbox, the approved body verbatim, then a signature block with the business's postal address and a
 * one-line way out. Never queued itself — SendOutreachEmail sends it on the worker.
 */
class OutreachMail extends Mailable
{
    public function __construct(
        public readonly OutreachEmail $email,
        public readonly Site $site,
    ) {}

    public function envelope(): Envelope
    {
        $from = new Address($this->email->from_email, $this->email->from_name ?: null);

        return new Envelope(from: $from, replyTo: [$from], subject: $this->email->subject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.outreach', with: [
            'body' => $this->email->body,
            'brand' => (string) $this->site->brand_name,
            'address' => $this->site->corporateAddressLine() ?? $this->primaryAddress(),
            'phone' => trim((string) $this->site->phone),
            'website' => (string) $this->site->domain_url,
        ]);
    }

    private function primaryAddress(): ?string
    {
        $office = $this->site->locations()->orderBy('created_at')->first();
        $line = trim((string) $office?->address);

        return $line !== '' ? $line : null;
    }
}
