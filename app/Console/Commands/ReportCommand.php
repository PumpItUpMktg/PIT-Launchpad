<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Reporting\TenantReport;
use Illuminate\Console\Command;

/**
 * `launchpad:report {site}` — a read-only, paste-able markdown snapshot of a tenant's full state (ten
 * sections, counts-first, capped lists, deterministic order). The recurring audit artifact + the
 * operator runbook's stage-gate checker. Changes nothing, dispatches nothing.
 */
class ReportCommand extends Command
{
    protected $signature = 'launchpad:report {site : Site id or brand name}
        {--section= : Emit only one section (intake|structure|pages|links|schema|launch|queue|engine|anomalies)}
        {--json : Machine output (v1 stub — RAG summary as JSON)}';

    protected $description = 'Emit a read-only markdown state snapshot for a tenant (audit + runbook stage-gate).';

    public function handle(TenantReport $report): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))->orWhere('brand_name', $this->argument('site'))->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        // The only non-deterministic line — callers diffing two runs ignore it (documented).
        $generatedAt = now()->toDateTimeString();

        if ($this->option('json')) {
            $this->line($report->json($site, $generatedAt));

            return self::SUCCESS;
        }

        $section = $this->option('section');
        $this->line($report->render($site, is_string($section) && $section !== '' ? $section : null, $generatedAt));

        return self::SUCCESS;
    }
}
