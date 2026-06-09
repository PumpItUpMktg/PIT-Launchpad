<?php

namespace App\Console\VendorProbes;

/**
 * Outcome of a single vendor probe: LIVE (real data path reached), READY
 * (platform configured + reachable, but the live check is per-tenant at runtime —
 * e.g. OAuth vendors), SKIP (credentials absent — safe before keys land), or FAIL
 * (reached but errored).
 */
enum ProbeStatus: string
{
    case Live = 'LIVE';
    case Ready = 'READY';
    case Skip = 'SKIP';
    case Fail = 'FAIL';
}
