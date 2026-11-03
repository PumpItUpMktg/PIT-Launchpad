<?php

namespace App\ColdOutreach;

use App\Enums\ColdContactStage;
use App\Integrations\ColdOutreach\WarmupLead;
use App\Models\ColdContact;
use App\Models\ColdOutreachConfig;

/**
 * Applies Instantly engagement to a contact — from a webhook event or a polled lead snapshot — and
 * moves it to Warm / Disqualified via {@see WarmthRule}. Polled counters are authoritative but never
 * lower a count (a webhook may have landed first); webhook events increment.
 */
class EngagementRecorder
{
    /** Instantly webhook event → the lead's interest status. */
    private const INTEREST_EVENTS = [
        'lead_interested' => 1,
        'lead_meeting_booked' => 2,
        'lead_meeting_completed' => 3,
        'lead_closed' => 4,
        'lead_out_of_office' => 0,
        'lead_not_interested' => -1,
        'lead_wrong_person' => -2,
    ];

    public function __construct(private readonly WarmthRule $rule) {}

    /** @return bool whether the event was recognised */
    public function applyEvent(ColdContact $contact, string $eventType, ColdOutreachConfig $config): bool
    {
        match (true) {
            $eventType === 'email_opened' => $contact->opens++,
            $eventType === 'reply_received' => $contact->replies++,
            $eventType === 'link_clicked' => $contact->clicks++,
            $eventType === 'email_bounced' => $contact->instantly_status = WarmthRule::BOUNCED,
            $eventType === 'lead_unsubscribed' => $contact->instantly_status = WarmthRule::UNSUBSCRIBED,
            $eventType === 'campaign_completed' => $contact->instantly_status ??= 3,
            array_key_exists($eventType, self::INTEREST_EVENTS) => $contact->interest_status = self::INTEREST_EVENTS[$eventType],
            default => null,
        };

        $known = in_array($eventType, ['email_opened', 'reply_received', 'link_clicked', 'email_bounced', 'lead_unsubscribed', 'campaign_completed'], true)
            || array_key_exists($eventType, self::INTEREST_EVENTS);

        if ($known) {
            $contact->last_event_at = now();
            $this->settle($contact, $config);
        }

        return $known;
    }

    public function applyLead(ColdContact $contact, WarmupLead $lead, ColdOutreachConfig $config): void
    {
        $contact->instantly_lead_id ??= $lead->id;
        $contact->instantly_status = $contact->instantly_status === WarmthRule::UNSUBSCRIBED || $contact->instantly_status === WarmthRule::BOUNCED
            ? $contact->instantly_status
            : $lead->status;
        $contact->opens = max($contact->opens, $lead->opens);
        $contact->replies = max($contact->replies, $lead->replies);
        $contact->clicks = max($contact->clicks, $lead->clicks);
        if ($lead->interest !== null) {
            $contact->interest_status = $lead->interest;
        }

        $this->settle($contact, $config);
    }

    /** Re-evaluate warmth and save. A promoted / invalid contact never moves back. */
    public function settle(ColdContact $contact, ColdOutreachConfig $config): void
    {
        if (! in_array($contact->stage, [ColdContactStage::Promoted, ColdContactStage::Invalid, ColdContactStage::Disqualified], true)) {
            $decision = $this->rule->evaluate($contact, $config);
            if ($decision !== null && $decision['stage'] !== $contact->stage) {
                $contact->stage = $decision['stage'];
                if ($decision['stage'] === ColdContactStage::Warm) {
                    $contact->warm_at = now();
                    $contact->warm_reason = $decision['reason'];
                } else {
                    $contact->disqualified_reason = $decision['reason'];
                }
            }
        }

        $contact->save();
    }
}
