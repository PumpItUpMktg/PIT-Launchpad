<?php

namespace App\Enums;

/**
 * The state of a (location × directory) citation (§ Citations). The scan (PR2) produces the found/gap states;
 * the submit → verify lifecycle (PR7) adds the rest. Grouped here so the column never needs a re-migration.
 *
 * Multi-location correctness: `sibling_listing` (this result belongs to a sibling — never a work-order item)
 * and `covered_by_sibling` (a one-per-business directory a sibling already satisfies) are the guards that stop
 * the destructive false-fix / false-duplicate / false-gap failures.
 */
enum CitationState: string
{
    // Scan-produced (PR2/PR3)
    case ListedCorrect = 'listed_correct';
    case NeedsFix = 'needs_fix';
    case Duplicate = 'duplicate';
    case NotListed = 'not_listed';
    case BlockedClientAction = 'blocked_client_action';
    case SiblingListing = 'sibling_listing';        // attributed to a sibling — never a task
    case CoveredBySibling = 'covered_by_sibling';   // one_per_business, a sibling covers it
    case AmbiguousReview = 'ambiguous_review';      // low-confidence / tied attribution → operator resolves

    // Submit → verify lifecycle (PR7)
    case Submitted = 'submitted';
    case PendingVerification = 'pending_verification';
    case Live = 'live';
    case Unverified = 'unverified';                 // 3 failed verification cycles → operator review
    case Fixed = 'fixed';
    case Rejected = 'rejected';
    case Stalled = 'stalled';                       // 3 work orders without resolution

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }

    /** States that count as "we have a live/correct listing" for coverage scoring. */
    public function isCovered(): bool
    {
        return in_array($this, [self::ListedCorrect, self::Live, self::Fixed, self::CoveredBySibling], true);
    }

    /** A result attributed to a sibling location — must never become a fix/duplicate/work-order item. */
    public function isSiblingOwned(): bool
    {
        return in_array($this, [self::SiblingListing, self::CoveredBySibling], true);
    }

    /**
     * Graded credit toward the Local Presence Score (§ Citations, PR3): 1.0 = a confirmed correct/live listing;
     * 0.5 = present-but-unconfirmed or mid-submission (found without a verified NAP, needs-fix, submitted,
     * pending); 0.0 = no listing, or a state that isn't this location's coverage to earn (gap, duplicate,
     * ambiguous/sibling attribution, blocked, rejected, stalled).
     */
    public function presenceCredit(): float
    {
        return match ($this) {
            self::ListedCorrect, self::Live, self::Fixed, self::CoveredBySibling => 1.0,
            self::Unverified, self::NeedsFix, self::Submitted, self::PendingVerification => 0.5,
            default => 0.0,
        };
    }
}
