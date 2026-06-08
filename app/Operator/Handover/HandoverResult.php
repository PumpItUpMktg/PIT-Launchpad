<?php

namespace App\Operator\Handover;

use App\Security\GateResult;

/**
 * The outcome of a site handover. `launched` is true only when §9's gate passed
 * and SiteLauncher marked the site Live; `repointed` records whether the WP
 * Connection was moved to a new host first (migrate mode). The gate checklist is
 * carried through for the red-until-green UI.
 */
final class HandoverResult
{
    public function __construct(
        public readonly bool $launched,
        public readonly bool $repointed,
        public readonly ?GateResult $gateResult,
        public readonly string $message,
    ) {}

    public function isBlocked(): bool
    {
        return ! $this->launched;
    }
}
