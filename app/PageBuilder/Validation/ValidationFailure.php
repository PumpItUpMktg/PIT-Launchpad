<?php

namespace App\PageBuilder\Validation;

/**
 * A single structured validation failure: the offending slot (or null for
 * page-level failures such as the thin-page guard), a reason code, and a
 * human-readable message.
 */
final class ValidationFailure
{
    public function __construct(
        public readonly ?string $slot,
        public readonly ValidationCode $code,
        public readonly string $message,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slot' => $this->slot,
            'code' => $this->code->value,
            'message' => $this->message,
        ];
    }
}
