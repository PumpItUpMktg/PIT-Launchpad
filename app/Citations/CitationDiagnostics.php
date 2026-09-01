<?php

namespace App\Citations;

use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\CitationFoundDomain;
use App\Models\CitationScanRun;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only end-to-end probe of the citation scan path for a location, so "the scan found nothing" resolves to
 * a specific stage: no catalog, no NAP, unconfigured/failing DataForSEO, a stalled worker, or organic SERP
 * simply not surfacing the listings. Runs one live DataForSEO query (small credit cost) to prove the data
 * channel actually works. Changes nothing.
 */
final class CitationDiagnostics
{
    public function __construct(private readonly DataForSeoClient $dfs) {}

    public function forLocation(Location $location): CitationDiagnosticReport
    {
        $activeDirectories = Directory::query()->where('is_active', true)->count();

        $profile = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->first();

        $napSummary = $profile !== null
            ? trim((string) $profile->business_name).' — '.trim(trim((string) $profile->city).', '.trim((string) $profile->state))
                .(($profile->phone_primary ?? '') !== '' ? ' · '.$profile->phone_primary : '')
            : null;

        $configured = filled(config('services.dataforseo.login')) && filled(config('services.dataforseo.password'));

        $dfsOk = null;
        $dfsError = null;
        $query = null;
        $organicRows = 0;
        $hits = [];
        $sample = [];

        if ($configured && $profile !== null) {
            $query = trim((string) $profile->business_name.' '.trim(trim((string) $profile->city).' '.trim((string) $profile->state)));
            try {
                $rows = $this->dfs->liveOrganic(
                    $query,
                    (int) config('services.dataforseo.location_code', 2840),
                    (string) config('services.dataforseo.language_code', 'en'),
                    (int) config('services.dataforseo.serp_depth', 20),
                );
                $organicRows = count($rows);
                $directories = Directory::query()->where('is_active', true)->get();
                foreach ($rows as $row) {
                    $domain = $this->normalizeDomain((string) $row['domain']);
                    if ($domain === '') {
                        continue;
                    }
                    $dir = $this->matchDirectory($domain, $directories);
                    if ($dir !== null) {
                        $hits[$this->normalizeDomain((string) $dir->domain)] = true;
                    } elseif (count($sample) < 8 && ! in_array($domain, $sample, true)) {
                        $sample[] = $domain;
                    }
                }
                $dfsOk = true;
            } catch (Throwable $e) {
                $dfsOk = false;
                $dfsError = $e->getMessage();
            }
        }

        $lastRun = CitationScanRun::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->latest('started_at')->first();

        return new CitationDiagnosticReport(
            locationName: (string) $location->name,
            activeDirectories: $activeDirectories,
            napPresent: $profile !== null,
            napSummary: $napSummary,
            dfsConfigured: $configured,
            dfsOk: $dfsOk,
            dfsError: $dfsError,
            probeQuery: $query,
            organicRows: $organicRows,
            directoryHits: array_keys($hits),
            sampleDomains: $sample,
            pendingJobs: (int) DB::table('jobs')->count(),
            failedScanJobs: (int) DB::table('failed_jobs')->where('payload', 'like', '%RunCitationScan%')->count(),
            foundDomains: CitationFoundDomain::query()->withoutGlobalScope(SiteScope::class)->where('location_id', $location->id)->count(),
            statusRows: CitationStatus::query()->withoutGlobalScope(SiteScope::class)->where('location_id', $location->id)->count(),
            lastRun: $lastRun !== null
                ? ($lastRun->finished_at !== null ? 'completed' : 'incomplete').' at '.(string) $lastRun->started_at
                    ." ({$lastRun->covered_count}/{$lastRun->directories_evaluated} covered)"
                : null,
        );
    }

    /** @param Collection<int, Directory> $directories */
    private function matchDirectory(string $domain, Collection $directories): ?Directory
    {
        foreach ($directories as $dir) {
            $d = $this->normalizeDomain((string) $dir->domain);
            if ($d !== '' && ($domain === $d || str_ends_with($domain, '.'.$d))) {
                return $dir;
            }
        }

        return null;
    }

    private function normalizeDomain(string $domain): string
    {
        $d = mb_strtolower(trim($domain));
        $d = preg_replace('#^https?://#', '', $d) ?? $d;
        $d = preg_replace('#^www\.#', '', $d) ?? $d;

        return rtrim((string) strtok($d, '/'), '.');
    }
}
