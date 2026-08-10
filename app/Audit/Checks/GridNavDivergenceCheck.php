<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Audit\Support\SurfaceSets;
use App\Models\Site;

/**
 * GRID-001 (Class C) — the homepage services grid and the header nav show DIFFERENT service sets. They
 * are independent queries (grid: created_at, capped 6, live-only; nav: featured/importance, capped 8),
 * so neither is the canonical set and they diverge. Flags the symmetric difference per tenant.
 */
final class GridNavDivergenceCheck implements AuditCheck
{
    public function __construct(private readonly SurfaceSets $surfaces) {}

    public function id(): string
    {
        return 'GRID-001';
    }

    public function defectClass(): string
    {
        return 'C';
    }

    public function severity(): string
    {
        return Severity::High;
    }

    public function title(): string
    {
        return 'Homepage services grid and header nav show different sets';
    }

    public function run(Site $site): array
    {
        $nav = $this->surfaces->navServiceLabels($site);
        $grid = $this->surfaces->gridLabels($site);
        if ($nav === [] && $grid === []) {
            return [];
        }

        $onlyNav = array_values(array_diff($nav, $grid));
        $onlyGrid = array_values(array_diff($grid, $nav));
        if ($onlyNav === [] && $onlyGrid === []) {
            return [];
        }

        $detail = 'nav-only: ['.implode(', ', $onlyNav).'] · grid-only: ['.implode(', ', $onlyGrid).']';

        return [new Finding($site->id, (string) $site->brand_name, null, $detail)];
    }
}
