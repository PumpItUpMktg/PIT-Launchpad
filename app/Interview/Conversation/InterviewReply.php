<?php

namespace App\Interview\Conversation;

/**
 * The interviewer's response for a turn: the next message to show the owner, plus
 * whether enough has been gathered to extract the seed + voice (the conversation's
 * terminal signal).
 */
final class InterviewReply
{
    public function __construct(
        public readonly string $message,
        public readonly bool $ready,
    ) {}
}
