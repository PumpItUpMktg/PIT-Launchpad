<?php

namespace App\Audit;

use App\Models\Site;

/**
 * The result of an audit run: the tenant × check finding matrix, plus the per-check rollup (how many
 * tenants and how many findings each check hit) and the worst severity seen (for the `--fail-on` gate).
 * Pure data + read helpers; the command renders it (table/json).
 */
final class AuditReport
{
    /**
     * @param  list<AuditCheck>  $checks
     * @param  list<Site>  $sites
     * @param  array<string, array<string, list<Finding>>>  $matrix  [siteId][checkId] => findings
     */
    public function __construct(
        public readonly array $checks,
        public readonly array $sites,
        public readonly array $matrix,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findingsFor(string $siteId, string $checkId): array
    {
        return $this->matrix[$siteId][$checkId] ?? [];
    }

    /**
     * Per-check rollup: tenants affected, total findings.
     *
     * @return array<string, array{tenants: int, findings: int}>
     */
    public function rollup(): array
    {
        $out = [];
        foreach ($this->checks as $check) {
            $tenants = 0;
            $findings = 0;
            foreach ($this->sites as $site) {
                $hits = $this->findingsFor($site->id, $check->id());
                if ($hits !== []) {
                    $tenants++;
                    $findings += count($hits);
                }
            }
            $out[$check->id()] = ['tenants' => $tenants, 'findings' => $findings];
        }

        return $out;
    }

    /** The worst severity that actually produced a finding, or '' when the run is clean. */
    public function worstSeverity(): string
    {
        $worst = '';
        foreach ($this->checks as $check) {
            foreach ($this->sites as $site) {
                if ($this->findingsFor($site->id, $check->id()) !== [] && Severity::rank($check->severity()) > Severity::rank($worst)) {
                    $worst = $check->severity();
                }
            }
        }

        return $worst;
    }

    /** Whether the run trips a `--fail-on=$threshold` gate. */
    public function trips(string $threshold): bool
    {
        foreach ($this->checks as $check) {
            foreach ($this->sites as $site) {
                if ($this->findingsFor($site->id, $check->id()) !== [] && Severity::trips($check->severity(), $threshold)) {
                    return true;
                }
            }
        }

        return false;
    }
}
