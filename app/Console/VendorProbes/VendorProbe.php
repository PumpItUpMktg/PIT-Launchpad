<?php

namespace App\Console\VendorProbes;

/**
 * One committed vendor's live-path smoke probe. Each adapter ships its own probe
 * class under VendorProbes/Probes/; the registry auto-discovers them, so adding a
 * vendor means adding a class — never editing the verify-vendors command.
 *
 * A probe makes one real, minimal outbound call (or none, when its credentials
 * are absent) and reports LIVE / SKIP / FAIL. It must catch its own failures and
 * never throw.
 */
interface VendorProbe
{
    /** Short column label, e.g. "Claude", "DataForSEO". */
    public function label(): string;

    /** Sort key for deterministic report ordering (lower runs earlier). */
    public function order(): int;

    /** Run the one-shot probe. Must not throw — catch and return a FAIL. */
    public function run(): ProbeResult;
}
