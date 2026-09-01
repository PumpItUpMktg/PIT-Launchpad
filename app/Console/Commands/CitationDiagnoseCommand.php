<?php

namespace App\Console\Commands;

use App\Citations\CitationDiagnostics;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;

/**
 * Diagnose why a citation scan is finding nothing (§ Citations). Probes the whole path for one location —
 * directory catalog, canonical NAP, DataForSEO credentials + a live query, queue/failed-job health, and scan
 * history — and prints a ✓/✗ checklist plus the single most likely cause. Read-only (one small live DataForSEO
 * call). `--location` picks a location; otherwise the first NAP-profiled location (optionally in `--site`).
 */
class CitationDiagnoseCommand extends Command
{
    protected $signature = 'launchpad:citation-diagnose
        {--location= : Diagnose this location by id}
        {--site= : Pick the first NAP-profiled location in this site}';

    protected $description = 'Diagnose why a citation scan finds nothing — catalog, NAP, DataForSEO, queue, history.';

    public function handle(CitationDiagnostics $diagnostics): int
    {
        $location = $this->resolveLocation();
        if ($location === null) {
            $this->error('No location to diagnose. Pass --location=<id>, or add a NAP-profiled location.');

            return self::FAILURE;
        }

        $report = $diagnostics->forLocation($location);

        $this->line("<info>Citation scan diagnostics — {$report->locationName}</info>");
        foreach ($report->lines() as $line) {
            $this->line('  '.$line);
        }
        $this->newLine();
        $this->line('<comment>Likely cause:</comment> '.$report->likelyCause());

        return self::SUCCESS;
    }

    private function resolveLocation(): ?Location
    {
        $id = $this->option('location');
        if (is_string($id) && $id !== '') {
            return Location::query()->withoutGlobalScope(SiteScope::class)->find($id);
        }

        $napLocationIds = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)->pluck('location_id');

        return Location::query()->withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $napLocationIds)
            ->when(is_string($this->option('site')) && $this->option('site') !== '',
                fn ($q) => $q->where('site_id', $this->option('site')))
            ->first();
    }
}
