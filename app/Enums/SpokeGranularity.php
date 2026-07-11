<?php

namespace App\Enums;

/**
 * Whether a head term earns its own spoke page or is folded into the pillar. The
 * split-vs-consolidate call surfaced during the prune when volume is ambiguous.
 */
enum SpokeGranularity: string
{
    case OwnPage = 'own_page';
    case Folded = 'folded';

    /**
     * Longtail routing: a supporting INFORMATIONAL keyword's home is the silo's blog target
     * queue — an article target, not a page or a section. Excluded from the build manifest
     * (only pillars + own_page spokes become pages); the queue enqueues at materialize.
     */
    case BlogTarget = 'blog_target';

    public function label(): string
    {
        return match ($this) {
            self::OwnPage => 'Own page',
            self::Folded => 'Folded into pillar',
            self::BlogTarget => 'Blog queue',
        };
    }
}
