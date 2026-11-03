<?php

namespace App\ColdOutreach;

use App\Enums\ColdContactStage;
use App\Models\ColdContact;
use App\Models\ColdOutreachConfig;

/**
 * Decides when a warming contact is warm enough for Brevo — or must leave the pipeline. Pure.
 *
 * Disqualifiers win over everything (an unsubscribe after a reply must never reach a marketing list):
 * bounced, unsubscribed, or tagged not-interested / wrong person / lost in Instantly.
 *
 * Warm, per the site's settings: a reply, an "interested"-family tag (interested, meeting booked /
 * completed, won), or — only when the site opts in — at least N opens. Opens alone are off by default:
 * open tracking is noisy (image proxies) and an open is not consent.
 */
class WarmthRule
{
    public const BOUNCED = -1;

    public const UNSUBSCRIBED = -2;

    /** @var list<int> Instantly lt_interest_status values that mean "engaged". */
    public const INTERESTED = [1, 2, 3, 4];

    /** @var list<int> not interested, wrong person, lost */
    public const NEGATIVE = [-1, -2, -3];

    /** @return array{stage: ColdContactStage, reason: string}|null null = no change */
    public function evaluate(ColdContact $contact, ColdOutreachConfig $config): ?array
    {
        if ($contact->instantly_status === self::BOUNCED) {
            return ['stage' => ColdContactStage::Disqualified, 'reason' => 'bounced'];
        }
        if ($contact->instantly_status === self::UNSUBSCRIBED) {
            return ['stage' => ColdContactStage::Disqualified, 'reason' => 'unsubscribed'];
        }
        if ($contact->interest_status !== null && in_array($contact->interest_status, self::NEGATIVE, true)) {
            return ['stage' => ColdContactStage::Disqualified, 'reason' => match ($contact->interest_status) {
                -2 => 'wrong person',
                -3 => 'lost',
                default => 'not interested',
            }];
        }

        if ($contact->stage !== ColdContactStage::Warming) {
            return null;
        }

        if ($config->warm_on_interested && $contact->interest_status !== null && in_array($contact->interest_status, self::INTERESTED, true)) {
            return ['stage' => ColdContactStage::Warm, 'reason' => 'marked interested'];
        }
        if ($config->warm_on_reply && $contact->replies > 0) {
            return ['stage' => ColdContactStage::Warm, 'reason' => 'replied'];
        }
        if ($config->warm_min_opens > 0 && $contact->opens >= $config->warm_min_opens) {
            return ['stage' => ColdContactStage::Warm, 'reason' => "opened {$contact->opens}×"];
        }

        return null;
    }
}
