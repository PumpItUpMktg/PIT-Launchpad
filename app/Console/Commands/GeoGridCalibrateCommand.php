<?php

namespace App\Console\Commands;

use App\GeoGrid\GeoGridCalibration;
use App\GeoGrid\LocalFalconGrid;
use App\Models\GeoGridScan;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * PR 5 calibration — compare a stored DataForSEO geo-grid scan against a Local Falcon point export, point by
 * point, and print the decision-gate verdict (accept the §5 formulas / tune zoom+depth and re-scan). No API,
 * no spend: it reads the scan's persisted points, so re-running after a formula tweak (`geo-grid-recompute`)
 * or a re-scan is cheap. The Local Falcon export is a simple lat/lng/rank CSV — see {@see LocalFalconGrid}.
 */
class GeoGridCalibrateCommand extends Command
{
    protected $signature = 'launchpad:geo-grid-calibrate {scan : geo_grid_scan id (the DataForSEO scan to check)}
        {--local= : Path to the Local Falcon point export (CSV: lat,lng,rank)}
        {--max-median=1 : Point-level pass threshold — max median absolute rank difference}
        {--min-agreement=0.9 : Coverage pass threshold — min found/not-found agreement (0–1)}';

    protected $description = 'Compare a DataForSEO geo-grid scan against a Local Falcon export (point-by-point) and print the calibration verdict.';

    public function handle(GeoGridCalibration $calibration): int
    {
        $scan = GeoGridScan::withoutGlobalScope(SiteScope::class)->with('points')->find($this->argument('scan'));
        if ($scan === null) {
            $this->error('No geo-grid scan with that id.');

            return self::FAILURE;
        }

        $localPath = (string) $this->option('local');
        if ($localPath === '') {
            $this->error('Provide --local=<path> to the Local Falcon export (CSV: lat,lng,rank).');

            return self::FAILURE;
        }

        try {
            $localFalcon = LocalFalconGrid::fromCsv($localPath, (int) $scan->depth_cap);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $result = $calibration->compare(
            $scan, $localFalcon,
            (float) $this->option('max-median'),
            (float) $this->option('min-agreement'),
        );

        $this->line("Scan <info>{$scan->id}</info> · {$scan->grid_size}×{$scan->grid_size} · zoom {$scan->zoom} · depth {$scan->depth_cap} · {$scan->points->count()} points");
        $this->line('Local Falcon export: '.count($localFalcon)." points ({$result['matched']} matched to grid cells)");
        $this->newLine();

        $this->table(['Comparison', 'Value'], [
            ['Points', (string) $result['total_points']],
            ['Found on both', (string) $result['both_found']],
            ['Median abs rank diff', $result['median_abs_diff'] ?? '— (no cell found on both)'],
            ['Mean abs rank diff', $result['mean_abs_diff'] ?? '—'],
            ['Found/not-found agreement', number_format($result['found_agreement'] * 100, 1).'%'],
        ]);

        $o = $result['aggregates']['ours'];
        $l = $result['aggregates']['local_falcon'];
        $this->table(['Aggregate', 'DataForSEO', 'Local Falcon (our formula)'], [
            ['ATRP', $this->fmt($o['atrp']), $this->fmt($l['atrp'])],
            ['ARP', $this->fmt($o['arp']), $this->fmt($l['arp'])],
            ['SoLV %', $this->fmt($o['solv']), $this->fmt($l['solv'])],
            ['Found rate %', $this->fmt($o['found_rate']), $this->fmt($l['found_rate'])],
        ]);

        $this->line('Point-level: '.($result['passes']['point_level'] ? '<info>PASS</info>' : '<error>FAIL</error>')
            .'   Coverage: '.($result['passes']['coverage'] ? '<info>PASS</info>' : '<error>FAIL</error>'));
        $this->newLine();

        $result['verdict'] === 'accept'
            ? $this->info('VERDICT: ACCEPT — '.$result['diagnosis'])
            : $this->warn('VERDICT: TUNE — '.$result['diagnosis']);

        return self::SUCCESS;
    }

    private function fmt(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2);
    }
}
