<?php

namespace App\Integrations\LocalGrid;

/**
 * A competitor appearing in the local pack, normalized.
 */
final class LocalPackCompetitor
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $domain = null,
    ) {}
}
