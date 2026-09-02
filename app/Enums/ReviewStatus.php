<?php

namespace App\Enums;

/**
 * The review's lifecycle in the operator approval queue (Review Capture §5/§9). `pending` on submission →
 * `approved` (operator) or `rejected`; `published` once it's live in a page's gated reviews section. Approve
 * enqueues publish; unpublish returns an approved review out of `published`.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Published = 'published';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The statuses whose reviews are live on-page (drive the publish provider). */
    public static function live(): array
    {
        return [self::Published];
    }
}
