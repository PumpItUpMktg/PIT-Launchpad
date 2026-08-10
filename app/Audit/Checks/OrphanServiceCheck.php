<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Audit\Support\SurfaceSets;
use App\Models\Site;

/**
 * STRUCT-001 (Class B/C) — a LIVE service/hub page that appears in NEITHER the header nav (top-level or
 * dropdown) NOR the homepage grid. Such a page is reachable only by luck (a stray body/FAQ link) — the
 * "Gas Lines exists but is in no nav and no grid" orphan. Flags each unreachable live service page.
 */
final class OrphanServiceCheck implements AuditCheck
{
    public function __construct(private readonly SurfaceSets $surfaces) {}

    public function id(): string
    {
        return 'STRUCT-001';
    }

    public function defectClass(): string
    {
        return 'B';
    }

    public function severity(): string
    {
        return Severity::High;
    }

    public function title(): string
    {
        return 'Live service page is in neither the nav nor the homepage grid (orphan)';
    }

    public function run(Site $site): array
    {
        $reachable = array_merge($this->surfaces->navServiceLabels($site), $this->surfaces->gridLabels($site));

        $out = [];
        foreach ($this->surfaces->liveServiceTitles($site) as $title) {
            if (! in_array($title, $reachable, true)) {
                $out[] = new Finding($site->id, (string) $site->brand_name, $title, 'live service page reachable from no nav and no grid');
            }
        }

        return $out;
    }
}
