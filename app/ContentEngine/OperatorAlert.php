<?php

namespace App\ContentEngine;

use App\Enums\AlertType;

/**
 * An operator alert raised by the funnel — nothing changes a live page silently.
 * §6c's flagged lane consumes these.
 */
final class OperatorAlert
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly AlertType $type,
        public readonly ?string $contentId,
        public readonly string $message,
        public readonly array $context = [],
    ) {}
}
