<?php

namespace App\Enums;

/**
 * A link plan's lifecycle. Proposed on unlock → Applied once the operator approves and the committer writes
 * the links + submits IndexNow. Never auto-applied.
 */
enum LinkPlanStatus: string
{
    case Proposed = 'proposed';
    case Applied = 'applied';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
