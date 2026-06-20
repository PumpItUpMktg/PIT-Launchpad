<?php

namespace App\Enums;

/**
 * A build-manifest page's lifecycle: queued → drafting → composed → in-review → published.
 * Drafting/Composed are the composition pipeline's intermediate states (set when generation
 * lands); the scaffold drives queued → in_review (gated) / published (auto).
 */
enum BuildStatus: string
{
    case Queued = 'queued';
    case Drafting = 'drafting';
    case Composed = 'composed';
    case InReview = 'in_review';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Drafting => 'Drafting',
            self::Composed => 'Composed',
            self::InReview => 'In review',
            self::Published => 'Published',
        };
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
