<?php

namespace App\Mail;

use App\Filament\Pages\Gathering\InterviewStep;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Operator notification (relay PR 3): the owner finished the client interview. Sent to whoever issued the
 * link, queued. The next move is theirs — open the Setup › Interview step and extract.
 */
class InterviewCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $siteId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->brand().' finished the onboarding interview');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.interview-completed', with: [
            'brand' => $this->brand(),
            'url' => InterviewStep::getUrl(),
        ]);
    }

    private function brand(): string
    {
        $site = Site::query()->withoutGlobalScopes()->find($this->siteId);
        $name = $site === null ? '' : trim((string) $site->brand_name);

        return $name !== '' ? $name : 'A client';
    }
}
