<?php

namespace App\Enums;

/**
 * A single proposed link's state within a plan. Proposed → the operator Approves or Rejects it → Applied
 * once the committer writes the anchor + re-publishes the source. Only Approved items are ever written.
 */
enum LinkPlanItemStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Applied = 'applied';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
