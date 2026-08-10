<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * COV-001 (Class G) — coverage-data quality on served towns (Markets): a numbered parse artifact baked
 * into a town name (e.g. "1, Abingdon"), or the same town appearing twice for one tenant. Both are the
 * generator ingesting/storing a malformed list. Flags each offending row.
 */
final class CoverageDataCheck implements AuditCheck
{
    public function id(): string
    {
        return 'COV-001';
    }

    public function defectClass(): string
    {
        return 'G';
    }

    public function severity(): string
    {
        return Severity::High;
    }

    public function title(): string
    {
        return 'Served-town data has parse artifacts or duplicates';
    }

    public function run(Site $site): array
    {
        $markets = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->get();

        $out = [];
        $seen = [];
        foreach ($markets as $market) {
            $name = trim((string) $market->name);
            if ($name === '') {
                continue;
            }
            if (preg_match('/^\s*\d+\s*,/', $name) === 1) {
                $out[] = new Finding($site->id, (string) $site->brand_name, $name, 'numbered parse artifact in town name');
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $out[] = new Finding($site->id, (string) $site->brand_name, $name, 'duplicate town');
            }
            $seen[$key] = true;
        }

        return $out;
    }
}
