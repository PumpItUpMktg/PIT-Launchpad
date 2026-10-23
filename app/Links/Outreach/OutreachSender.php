<?php

namespace App\Links\Outreach;

use App\Enums\LinkProspectStatus;
use App\Jobs\SendOutreachEmail;
use App\Models\LinkProspect;
use App\Models\OutreachEmail;
use App\Models\OutreachKit;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Approve & send" (§ Links). One click, one email: the guard runs inside a transaction that locks the
 * site row, so two clicks — or two operators — cannot both take the last slot of the month. The approved
 * row is written first (status queued, holding its place against the cap), then the send rides the queue;
 * the worker marks it sent or failed. Nothing here is ever called by a schedule.
 */
final class OutreachSender
{
    public function __construct(private readonly OutreachGuard $guard) {}

    /**
     * @throws OutreachRefused when the guard says no — the reasons are the message
     */
    public function approve(Site $site, LinkProspect $prospect, string $kind, string $subject, string $body, ?OutreachKit $kit, ?User $by): OutreachEmail
    {
        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($subject === '' || $body === '') {
            throw new OutreachRefused(['The subject and the email body are both required.']);
        }
        if (! in_array($kind, [OutreachEmail::PITCH, OutreachEmail::FOLLOW_UP], true)) {
            throw new RuntimeException("Unknown outreach kind {$kind}.");
        }

        return DB::transaction(function () use ($site, $prospect, $kind, $subject, $body, $kit, $by): OutreachEmail {
            // Lock the tenant so the month's count cannot change under us between the check and the insert.
            $locked = Site::withoutGlobalScopes()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $fresh = LinkProspect::withoutGlobalScope(SiteScope::class)->whereKey($prospect->id)->firstOrFail();
            $result = $this->guard->check($locked, $fresh, $kind);
            if (! $result->ok()) {
                throw new OutreachRefused($result->reasons());
            }
            $sender = Sender::for($locked);
            if ($sender === null) {
                throw new OutreachRefused(['No sender on the tenant\'s own domain.']);
            }

            $email = OutreachEmail::withoutGlobalScope(SiteScope::class)->create([
                'site_id' => $locked->id,
                'link_prospect_id' => $fresh->id,
                'outreach_kit_id' => $kit?->id,
                'location_id' => $kit?->location_id,
                'sent_by' => $by?->id,
                'kind' => $kind,
                'to_email' => strtolower(trim((string) $fresh->contact_email)),
                'from_email' => $sender['email'],
                'from_name' => $sender['name'],
                'subject' => $subject,
                'body' => $body,
                'status' => OutreachEmail::QUEUED,
                'approved_at' => now(),
            ]);
            if ($fresh->status === LinkProspectStatus::New) {
                $fresh->forceFill(['status' => LinkProspectStatus::Contacted])->save();
            }

            DB::afterCommit(fn () => SendOutreachEmail::dispatch((string) $email->id));

            return $email;
        });
    }
}
