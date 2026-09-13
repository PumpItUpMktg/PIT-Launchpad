<?php

namespace App\Mail;

use App\Models\InterviewInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The client interview link email (relay PR 3). Queued — nothing outbound blocks a web request. Tiny
 * payload: the invite id + the plaintext token; the brand, link and copy resolve at send time on the
 * worker. Branded to the tenant, addressed to the owner in the second person.
 */
class InterviewInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $inviteId,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A few questions about '.$this->brand().' for your new website');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.interview-invite', with: [
            'brand' => $this->brand(),
            'url' => route('interview.show', ['token' => $this->token]),
            'days' => $this->days(),
        ]);
    }

    private function invite(): ?InterviewInvite
    {
        return InterviewInvite::query()->withoutGlobalScopes()->find($this->inviteId);
    }

    private function brand(): string
    {
        $site = $this->invite()?->site;
        $name = $site === null ? '' : trim((string) $site->brand_name);

        return $name !== '' ? $name : 'your business';
    }

    private function days(): int
    {
        $invite = $this->invite();

        return $invite === null ? 30 : max(1, (int) now()->diffInDays($invite->expires_at, false));
    }
}
