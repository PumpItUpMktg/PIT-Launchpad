<?php

namespace App\Interview\Invites;

use App\Models\InterviewInvite;

/**
 * The result of issuing a client interview link: the stored row (hash only) and the one-time plaintext token
 * to put in the link. The plaintext exists only here — it is never stored and cannot be recovered later;
 * a lost link is re-issued, not looked up.
 */
final class IssuedInvite
{
    public function __construct(
        public readonly InterviewInvite $invite,
        public readonly string $plaintext,
    ) {}
}
