<?php

namespace App\Integrations\ColdOutreach;

/**
 * A lead as Instantly reports it. `status`: 1 active, 2 paused, 3 completed, -1 bounced,
 * -2 unsubscribed, -3 skipped. `interest` (lt_interest_status): 1 interested, 2 meeting booked,
 * 3 meeting completed, 4 won, 0 out of office, -1 not interested, -2 wrong person, -3 lost, -4 no show.
 */
final readonly class WarmupLead
{
    public function __construct(
        public ?string $id,
        public string $email,
        public ?int $status = null,
        public int $opens = 0,
        public int $replies = 0,
        public int $clicks = 0,
        public ?int $interest = null,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        return new self(
            id: is_string($row['id'] ?? null) ? $row['id'] : null,
            email: (string) ($row['email'] ?? ''),
            status: isset($row['status']) ? (int) $row['status'] : null,
            opens: (int) ($row['email_open_count'] ?? 0),
            replies: (int) ($row['email_reply_count'] ?? 0),
            clicks: (int) ($row['email_click_count'] ?? 0),
            interest: isset($row['lt_interest_status']) ? (int) $row['lt_interest_status'] : null,
        );
    }
}
