<?php

namespace App\Enums;

/**
 * Lifecycle of an owner interview (gathering relay). `Complete` is a statement of "we're done
 * talking" — thin sections are allowed (ending early is an operator control, not a failure);
 * `Abandoned` marks a call that never resumed. The transcript persists regardless — extraction
 * re-runs against it at any status.
 */
enum InterviewStatus: string
{
    case InProgress = 'in_progress';
    case Complete = 'complete';
    case Abandoned = 'abandoned';
}
