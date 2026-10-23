<?php

namespace App\Jobs;

use App\Links\Outreach\Sender;
use App\Mail\OutreachMail;
use App\Models\LinkProspect;
use App\Models\OutreachEmail;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deliver one approved outreach email on the worker (§ Links). Re-checks the sender-domain rule at the
 * last moment, sends once (never retried — a second attempt could double a delivered email), and records
 * sent or failed with the provider's words. A failed row no longer counts against the month.
 */
class SendOutreachEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $emailId) {}

    public function handle(): void
    {
        $email = OutreachEmail::withoutGlobalScope(SiteScope::class)->find($this->emailId);
        if ($email === null || $email->status !== OutreachEmail::QUEUED) {
            return;
        }
        $site = Site::withoutGlobalScopes()->find($email->site_id);
        if ($site === null || ! Sender::onOwnDomain($site, $email->from_email)) {
            $email->forceFill(['status' => OutreachEmail::FAILED, 'error' => 'Refused: the sender is not on the tenant\'s own domain.'])->save();

            return;
        }
        if (! (bool) config('launchpad.links.outreach.enabled', true)) {
            $email->forceFill(['status' => OutreachEmail::FAILED, 'error' => 'Refused: outreach is switched off for the platform.'])->save();

            return;
        }

        try {
            $sent = Mail::to($email->to_email)->send(new OutreachMail($email, $site));
            $messageId = $sent?->getMessageId();
            $email->forceFill(['status' => OutreachEmail::SENT, 'sent_at' => now(), 'provider_message_id' => $messageId !== null ? Str::limit($messageId, 250, '') : null, 'error' => null])->save();
            LinkProspect::withoutGlobalScope(SiteScope::class)->whereKey($email->link_prospect_id)
                ->update(['emails_sent' => LinkProspect::withoutGlobalScope(SiteScope::class)->whereKey($email->link_prospect_id)->value('emails_sent') + 1, 'last_emailed_at' => now()]);
        } catch (Throwable $e) {
            $email->forceFill(['status' => OutreachEmail::FAILED, 'error' => Str::limit($e->getMessage(), 1000)])->save();
        }
    }
}
