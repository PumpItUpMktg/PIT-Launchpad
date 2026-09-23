<?php

namespace App\Mail;

use App\Console\Commands\TestMailCommand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The one-line "does outbound mail work" message {@see TestMailCommand} sends. */
class MailTestMail extends Mailable
{
    public function __construct(public string $viaMailer) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Launchpad mail test');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>This is a test message from Launchpad, sent through the <strong>'.e($this->viaMailer).'</strong> mailer at '
            .now()->toDateTimeString().' UTC. If you are reading it, outbound mail works.</p>');
    }
}
