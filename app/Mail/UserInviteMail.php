<?php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "you have access" email a granted user receives: which site, at what role (and what that role
 * can do), and a set-your-password link (a 24-hour token on the admin panel's broker). Sent NOW from the
 * Users board, never queued — the operator is on the page to learn whether it went.
 */
class UserInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userId,
        public string $siteId,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your access to '.$this->brand());
    }

    public function content(): Content
    {
        $user = User::query()->find($this->userId);

        return new Content(markdown: 'mail.user-invite', with: [
            'name' => trim((string) $user?->name),
            'brand' => $this->brand(),
            'role' => $user?->role->label() ?? 'Site Admin',
            'powers' => $user?->role->isSiteAdmin()
                ? 'You can run the site: review and edit drafts, approve and publish pages and posts, and manage the territory and services. Credentials, billing controls and user access stay with your marketing team.'
                : 'You can view the performance dashboard for the site.',
            'url' => $this->resetUrl,
            'loginUrl' => url('/admin/login'),
        ]);
    }

    private function brand(): string
    {
        $site = Site::query()->withoutGlobalScopes()->find($this->siteId);
        $name = $site === null ? '' : trim((string) $site->brand_name);

        return $name !== '' ? $name : 'your site';
    }
}
