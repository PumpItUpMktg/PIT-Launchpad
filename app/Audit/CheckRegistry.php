<?php

namespace App\Audit;

use App\Audit\Checks\CoverageDataCheck;
use App\Audit\Checks\DuplicatePostCheck;
use App\Audit\Checks\GridNavDivergenceCheck;
use App\Audit\Checks\IndexableStagingCheck;
use App\Audit\Checks\OrphanServiceCheck;
use App\Audit\Checks\PriceRangeFallbackCheck;
use App\Audit\Checks\R2DevImageCheck;
use App\Audit\Checks\SharedAddressCheck;
use App\Audit\Checks\SpokeServicePinCheck;
use App\Audit\Checks\UnformattedRecordCheck;
use Illuminate\Contracts\Container\Container;

/**
 * The ordered check set the audit runs. Adding a check is one line here plus its class — the id/class/
 * severity live on the check itself. Container-resolved so a check can constructor-inject collaborators
 * (e.g. SurfaceSets → SiteProfileAssembler). Ordered critical-first for display.
 */
final class CheckRegistry
{
    /** @var list<class-string<AuditCheck>> */
    private const CHECKS = [
        SpokeServicePinCheck::class,     // SLOT-001  critical
        IndexableStagingCheck::class,    // NOINDEX-001 critical
        SharedAddressCheck::class,       // NAP-001   critical
        PriceRangeFallbackCheck::class,  // SLOT-002  high
        GridNavDivergenceCheck::class,   // GRID-001  high
        OrphanServiceCheck::class,       // STRUCT-001 high
        CoverageDataCheck::class,        // COV-001   high
        R2DevImageCheck::class,          // IMG-001   high
        DuplicatePostCheck::class,       // BLOG-001  high
        UnformattedRecordCheck::class,   // CASE-001  medium
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<AuditCheck>
     */
    public function all(): array
    {
        return array_map(fn (string $class): AuditCheck => $this->container->make($class), self::CHECKS);
    }
}
