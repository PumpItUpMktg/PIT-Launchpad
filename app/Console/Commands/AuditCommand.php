<?php

namespace App\Console\Commands;

use App\Audit\AuditReport;
use App\Audit\AuditRunner;
use App\Audit\CheckRegistry;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * launchpad:audit — the cross-tenant output audit harness. Runs the {@see CheckRegistry} check set
 * across every tenant (or one), prints a per-tenant × per-check matrix plus a per-check rollup, and can
 * exit non-zero on a `--fail-on` threshold so it can gate a build or a Launch action. Read-only: it
 * inspects control-plane data and reports; it never edits a tenant.
 */
class AuditCommand extends Command
{
    protected $signature = 'launchpad:audit
        {--tenant= : Site id or brand name; omit to run across every tenant}
        {--format=table : table|json}
        {--fail-on= : critical|high|any — exit non-zero when a finding at/above this level exists}';

    protected $description = 'Cross-tenant output audit — run the check set across tenants and report the defect matrix.';

    public function handle(CheckRegistry $registry, AuditRunner $runner): int
    {
        $sites = $this->resolveSites();
        if ($sites === []) {
            $this->error('No tenants found.');

            return self::FAILURE;
        }

        $report = $runner->run($sites, $registry->all());

        if ((string) $this->option('format') === 'json') {
            $this->line((string) json_encode($this->jsonPayload($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTable($report);
        }

        $failOn = (string) ($this->option('fail-on') ?? '');
        if ($failOn !== '' && $report->trips($failOn)) {
            $this->newLine();
            $this->error("Audit gate: findings at or above '{$failOn}'.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<Site>
     */
    private function resolveSites(): array
    {
        $tenant = $this->option('tenant');
        if (is_string($tenant) && $tenant !== '') {
            $site = Site::withoutGlobalScopes()->find($tenant)
                ?? Site::withoutGlobalScopes()->where('brand_name', $tenant)->first();

            return $site !== null ? [$site] : [];
        }

        return Site::withoutGlobalScopes()->orderBy('brand_name')->get()->all();
    }

    private function renderTable(AuditReport $report): void
    {
        // Per-check rollup: how wide each defect spreads.
        $rollup = $report->rollup();
        $rows = [];
        foreach ($report->checks as $check) {
            $r = $rollup[$check->id()];
            $rows[] = [
                $check->id(),
                $check->defectClass(),
                $check->severity(),
                $r['tenants'].'/'.count($report->sites),
                (string) $r['findings'],
                $check->title(),
            ];
        }
        $this->table(['Check', 'Class', 'Severity', 'Tenants', 'Findings', 'Title'], $rows);

        // Per-tenant detail — only tenants and checks that actually hit.
        foreach ($report->sites as $site) {
            $lines = [];
            foreach ($report->checks as $check) {
                foreach ($report->findingsFor($site->id, $check->id()) as $finding) {
                    $where = $finding->page !== null ? '  ['.$finding->page.']' : '';
                    $lines[] = sprintf('  %s %s%s — %s', $check->id(), $check->severity(), $where, $finding->detail);
                }
            }
            if ($lines !== []) {
                $this->newLine();
                $this->line('<comment>'.$site->brand_name.'</comment>');
                foreach ($lines as $line) {
                    $this->line($line);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(AuditReport $report): array
    {
        $checks = [];
        foreach ($report->checks as $check) {
            $checks[] = [
                'id' => $check->id(),
                'class' => $check->defectClass(),
                'severity' => $check->severity(),
                'title' => $check->title(),
                'rollup' => $report->rollup()[$check->id()],
            ];
        }

        $tenants = [];
        foreach ($report->sites as $site) {
            $findings = [];
            foreach ($report->checks as $check) {
                foreach ($report->findingsFor($site->id, $check->id()) as $finding) {
                    $findings[] = [
                        'check' => $check->id(),
                        'severity' => $check->severity(),
                        'page' => $finding->page,
                        'detail' => $finding->detail,
                    ];
                }
            }
            $tenants[] = ['id' => $site->id, 'brand_name' => $site->brand_name, 'findings' => $findings];
        }

        return ['checks' => $checks, 'tenants' => $tenants, 'worst_severity' => $report->worstSeverity()];
    }
}
