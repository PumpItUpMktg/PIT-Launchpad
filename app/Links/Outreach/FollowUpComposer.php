<?php

namespace App\Links\Outreach;

use App\Models\OutreachEmail;
use App\Models\Site;

/**
 * The one follow-up (§ Links): a short, plain nudge written from the pitch that went out — no model call,
 * nothing new to claim. The person edits it before it goes, like the pitch.
 */
final class FollowUpComposer
{
    /** @return array{subject: string, body: string} */
    public function compose(Site $site, OutreachEmail $pitch): array
    {
        $name = trim((string) ($pitch->from_name ?: $site->brand_name));
        $when = $pitch->approved_at->toFormattedDateString();
        $subject = str_starts_with(strtolower($pitch->subject), 're:') ? $pitch->subject : 'Re: '.$pitch->subject;
        $body = "Hello,\n\nI wrote on {$when} about {$site->brand_name} and wanted to make sure the note reached the right person. "
            ."If it is something you can help with, I would be glad to hear what the next step is; if not, a quick no is fine and I will not write again.\n\n"
            ."Thank you,\n{$name}";

        return ['subject' => $subject, 'body' => $body];
    }
}
