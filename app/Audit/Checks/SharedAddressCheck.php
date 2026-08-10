<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Models\Site;

/**
 * NAP-001 (Class E) — a tenant's corporate address matches the AGENCY's address (config
 * `launchpad.audit.agency_address`) or ANOTHER tenant's. Either is a client about to publish the wrong
 * business location. A hard launch blocker candidate. No per-tenant special-casing — the agency address
 * is config, and cross-tenant comparison is data-driven.
 */
final class SharedAddressCheck implements AuditCheck
{
    public function id(): string
    {
        return 'NAP-001';
    }

    public function defectClass(): string
    {
        return 'E';
    }

    public function severity(): string
    {
        return Severity::Critical;
    }

    public function title(): string
    {
        return 'Tenant address matches the agency or another tenant';
    }

    public function run(Site $site): array
    {
        $addr = $this->normalize($site->corporateAddressLine());
        if ($addr === '') {
            return [];
        }

        $out = [];

        $agency = $this->normalize((string) config('launchpad.audit.agency_address', ''));
        if ($agency !== '' && $addr === $agency) {
            $out[] = new Finding($site->id, (string) $site->brand_name, null, 'corporate address matches the agency address');
        }

        $twin = Site::withoutGlobalScopes()
            ->whereKeyNot($site->id)
            ->get()
            ->first(fn (Site $o): bool => $this->normalize($o->corporateAddressLine()) === $addr);
        if ($twin !== null) {
            $out[] = new Finding($site->id, (string) $site->brand_name, null, 'corporate address matches another tenant: '.(string) $twin->brand_name);
        }

        return $out;
    }

    private function normalize(?string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $s)));
    }
}
