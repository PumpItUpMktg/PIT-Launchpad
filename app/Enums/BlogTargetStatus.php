<?php

namespace App\Enums;

/**
 * Lifecycle of a blog target — an unconsumed informational keyword queued for a directed
 * article. `queued` → `drafted` (an article was drafted against it) → `published`.
 * `dismissed` is the operator opt-out. A consumed target is never re-assigned.
 */
enum BlogTargetStatus: string
{
    case Queued = 'queued';
    case Drafted = 'drafted';
    case Published = 'published';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
