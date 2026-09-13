<?php

namespace App\Enums;

/**
 * Who is typing the answers in an owner interview. The engine's questions are the same either way; the
 * framing is not. OPERATOR: the operator is on a call and types the owner's answers (the Setup step).
 * OWNER: the owner is answering directly on the client-facing link (relay PR 2) — second person, plain
 * language, a word on why a sensitive detail is asked for. The turn role records which it was.
 */
enum InterviewAudience: string
{
    case Operator = 'operator';
    case Owner = 'owner';

    /** The InterviewTurn role an answer from this audience is stored under. */
    public function role(): string
    {
        return $this->value;
    }
}
