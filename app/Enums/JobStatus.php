<?php

namespace App\Enums;

/**
 * The Job Capture lifecycle (§3). The full ordered flow is:
 *
 *   PendingAssignment (Joby only) → Assigned → Captured → Enhancing → Review → Approved | Rejected → Published
 *
 * A `Manual` job (a walk-in) starts at {@see self::Captured} — there is no assignment step. `Joby` jobs land
 * at {@see self::PendingAssignment} and an operator assigns a tech before the work happens. Enhancement (§7)
 * moves a job Captured → Enhancing → Review; operator approval (§8) moves it Review → Approved, and only an
 * approved job is pushed to WordPress → Published. Rejected and (un)approval are reversible operator actions,
 * so nothing here is a hard delete.
 */
enum JobStatus: string
{
    case PendingAssignment = 'pending_assignment';
    case Assigned = 'assigned';
    case Captured = 'captured';
    case Enhancing = 'enhancing';
    case Review = 'review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Publishing = 'publishing';
    case PublishFailed = 'publish_failed';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::PendingAssignment => 'Pending assignment',
            self::Assigned => 'Assigned',
            self::Captured => 'Captured',
            self::Enhancing => 'Enhancing',
            self::Review => 'In review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Publishing => 'Publishing',
            self::PublishFailed => 'Publish failed',
            self::Published => 'Published',
        };
    }

    /** Statuses that sit in the operator review queue (§8) — awaiting a human decision. */
    public function isAwaitingReview(): bool
    {
        return $this === self::Review;
    }

    /** Live on WordPress — an approved job that has been pushed (§9). */
    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
