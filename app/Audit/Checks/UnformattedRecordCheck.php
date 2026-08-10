<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

/**
 * CASE-001 (Class A) — a Service record whose list items (symptoms / scope / process / cost factors)
 * are all-lowercase fragments (e.g. "slow drain", "assess situation on site"). The renderer prints
 * them verbatim (no sentence-casing at render), so they ship lowercase. Flags the source records so
 * the fix — case at render or enforce at record — has a target list.
 */
final class UnformattedRecordCheck implements AuditCheck
{
    private const FIELDS = ['symptoms', 'scope_items', 'process_steps', 'cost_factors'];

    public function id(): string
    {
        return 'CASE-001';
    }

    public function defectClass(): string
    {
        return 'A';
    }

    public function severity(): string
    {
        return Severity::Medium;
    }

    public function title(): string
    {
        return 'Service record has all-lowercase list items (render prints them verbatim)';
    }

    public function run(Site $site): array
    {
        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->get();

        $out = [];
        foreach ($services as $service) {
            $bad = [];
            foreach (self::FIELDS as $field) {
                foreach ((array) ($service->{$field} ?? []) as $item) {
                    if (is_string($item) && $this->isLowercaseFragment($item)) {
                        $bad[$field] = true;
                    }
                }
            }
            if ($bad !== []) {
                $out[] = new Finding($site->id, (string) $site->brand_name, (string) $service->name, 'lowercase items in: '.implode(', ', array_keys($bad)));
            }
        }

        return $out;
    }

    /** True when the string has letters and none are uppercase — a raw, un-cased fragment. */
    private function isLowercaseFragment(string $s): bool
    {
        $s = trim($s);

        return $s !== '' && preg_match('/\p{L}/u', $s) === 1 && $s === mb_strtolower($s);
    }
}
