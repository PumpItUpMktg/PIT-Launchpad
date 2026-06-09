<?php

namespace App\Console\VendorProbes;

/**
 * Outcome of a single vendor probe: LIVE (real path reached), SKIP (credentials
 * absent — safe before keys land), or FAIL (reached but errored).
 */
enum ProbeStatus: string
{
    case Live = 'LIVE';
    case Skip = 'SKIP';
    case Fail = 'FAIL';
}
