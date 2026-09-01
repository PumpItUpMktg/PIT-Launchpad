<?php

namespace App\Citations\Ui;

use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Models\CitationStatus;

/**
 * Resolves the single status chip the UI shows for a (location × directory) citation (§ Citations UI, PR C).
 *
 * The precedence is deliberate and first-match-wins. The load-bearing rule is that **mismatch outranks live**:
 * a citation we verified in June that an August scan finds with a changed phone reads as `Mismatch`, not
 * `Live`. That's only expressible because presence and lifecycle are separate axes (PR A) — a single collapsed
 * status could never hold "verified AND now wrong" at once.
 */
final class CitationChip
{
    /**
     * @return array{key: string, label: string, color: string}
     */
    public static function for(?CitationStatus $status, bool $eligible): array
    {
        if (! $eligible) {
            return self::chip('not_relevant', 'Not relevant', 'gray');
        }
        if ($status === null) {
            return self::chip('not_scanned', 'Not scanned', 'gray');
        }

        return match (true) {
            $status->lifecycle === CitationLifecycleState::Stalled => self::chip('stalled', 'Stalled', 'danger'),
            $status->lifecycle === CitationLifecycleState::Rejected => self::chip('rejected', 'Rejected', 'danger'),
            $status->presence === CitationPresence::PresentMismatch => self::chip('mismatch', 'Mismatch', 'warning'),
            $status->lifecycle === CitationLifecycleState::Submitted => self::chip('submitted', 'Submitted', 'info'),
            $status->presence === CitationPresence::PresentMatch => self::chip('live', 'Live', 'success'),
            $status->presence === CitationPresence::Absent => self::chip('missing', 'Missing', 'gray'),
            // Presence is Unknown here (all other values handled above). If a listing was actually found but
            // attribution was too weak to confirm it's this location's (common on a multi-location brand), the
            // operator reviews it — it is NOT "not scanned".
            $status->found_url !== null => self::chip('needs_review', 'Needs review', 'warning'),
            default => self::chip('not_scanned', 'Not scanned', 'gray'),
        };
    }

    /**
     * @return array{key: string, label: string, color: string}
     */
    private static function chip(string $key, string $label, string $color): array
    {
        return ['key' => $key, 'label' => $label, 'color' => $color];
    }
}
