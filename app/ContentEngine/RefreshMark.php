<?php

namespace App\ContentEngine;

/**
 * A candidate routed to "refresh an existing page" instead of being duplicated.
 * The refresh itself routes through review (§6b/c); §6a only marks + alerts.
 */
final class RefreshMark
{
    public function __construct(
        public readonly string $candidateTitle,
        public readonly ?string $existingContentId,
        public readonly float $similarity,
    ) {}
}
