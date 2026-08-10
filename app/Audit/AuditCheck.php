<?php

namespace App\Audit;

use App\Models\Site;

/**
 * One cross-tenant output check. Each has a STABLE id (`SLOT-001`, `CTA-001`, …) so the report, the
 * fix, and the regression test all reference the same thing — and adding a new check is just a new
 * class registered in {@see CheckRegistry}. A check is read-only: it inspects a tenant's control-plane
 * data (and config) and returns the instances where the generator produced a defect. It never edits.
 *
 * v1 checks are deterministic control-plane/config checks (no network), so they run unattended and are
 * testable with factories. Fetch-based checks (rendered-HTML canonical/anchor/robots verification) are
 * a later layer that plugs into the same registry.
 */
interface AuditCheck
{
    /** Stable id, e.g. "SLOT-001". */
    public function id(): string;

    /** Defect class letter A–G from the relay. */
    public function defectClass(): string;

    /** One of the {@see Severity} constants. */
    public function severity(): string;

    /** One-line human title. */
    public function title(): string;

    /**
     * The defect instances for this tenant (empty = clean).
     *
     * @return list<Finding>
     */
    public function run(Site $site): array;
}
