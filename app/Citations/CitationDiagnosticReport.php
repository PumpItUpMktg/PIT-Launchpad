<?php

namespace App\Citations;

/**
 * The end-to-end health of the citation scan path for one location (see {@see CitationDiagnostics}). Read-only —
 * it's what turns "the scan found nothing" into a specific cause: empty catalog, missing NAP, unconfigured or
 * failing DataForSEO, a stalled worker, or organic SERP genuinely not surfacing the listings.
 */
final class CitationDiagnosticReport
{
    /**
     * @param  list<string>  $directoryHits  catalog directories the live probe matched
     * @param  list<string>  $sampleDomains  a few unmatched result domains, for context
     */
    public function __construct(
        public readonly string $locationName,
        public readonly int $activeDirectories,
        public readonly bool $napPresent,
        public readonly ?string $napSummary,
        public readonly bool $dfsConfigured,
        public readonly ?bool $dfsOk,
        public readonly ?string $dfsError,
        public readonly ?string $probeQuery,
        public readonly int $organicRows,
        public readonly array $directoryHits,
        public readonly array $sampleDomains,
        public readonly int $pendingJobs,
        public readonly int $failedScanJobs,
        public readonly int $foundDomains,
        public readonly int $statusRows,
        public readonly ?string $lastRun,
    ) {}

    /**
     * The single most likely reason the scan isn't surfacing citations — the first failing stage down the path.
     */
    public function likelyCause(): string
    {
        return match (true) {
            $this->activeDirectories === 0 => 'The directory catalog is empty — click "Seed directory catalog" first, then re-scan.',
            ! $this->napPresent => 'This location has no canonical NAP — import its GBP or click "Backfill NAPs from GBP".',
            ! $this->dfsConfigured => 'DataForSEO credentials are not set (DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD) — the scan can\'t query Google, so every scan fails. Set them in the environment.',
            $this->dfsOk === false => 'The DataForSEO call failed ('.($this->dfsError ?? 'unknown error').') — usually bad credentials or an exhausted quota.',
            $this->failedScanJobs > 0 => "Scans are failing on the worker ({$this->failedScanJobs} failed scan job(s)) — check the failed reason (launchpad:queue-diagnose) and clear once fixed.",
            $this->pendingJobs > 0 && $this->foundDomains === 0 => "Scans are queued ({$this->pendingJobs} pending) but nothing has been processed — the queue worker is likely DOWN.",
            $this->organicRows === 0 => 'DataForSEO returned zero organic results for the brand query — the query or location code may be off.',
            $this->directoryHits === [] => 'Google returns results but none are catalog directories for this business — organic-SERP detection is missing the listings; targeted per-directory checks would find more.',
            default => 'The scan path looks healthy — '.count($this->directoryHits).' directory listing(s) surfaced on the live probe.',
        };
    }

    /** Notification severity — 'danger' for a hard block, 'warning' for weak coverage, 'success' when healthy. */
    public function severity(): string
    {
        $blocked = $this->activeDirectories === 0
            || ! $this->napPresent
            || ! $this->dfsConfigured
            || $this->dfsOk === false
            || $this->failedScanJobs > 0
            || ($this->pendingJobs > 0 && $this->foundDomains === 0);

        if ($blocked) {
            return 'danger';
        }

        return ($this->organicRows === 0 || $this->directoryHits === []) ? 'warning' : 'success';
    }

    /**
     * Human-readable checklist lines, one per stage (✓/✗), for a notification or console output.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $mark = fn (bool $ok): string => $ok ? '✓' : '✗';

        $lines = [
            $mark($this->activeDirectories > 0)." Directory catalog: {$this->activeDirectories} active",
            $mark($this->napPresent).' Canonical NAP: '.($this->napPresent ? ($this->napSummary ?? 'present') : 'MISSING'),
            $mark($this->dfsConfigured).' DataForSEO credentials: '.($this->dfsConfigured ? 'configured' : 'NOT SET'),
        ];

        if ($this->dfsOk !== null) {
            $lines[] = $this->dfsOk
                ? "✓ Live probe: {$this->organicRows} organic rows, ".count($this->directoryHits).' directory hit(s)'
                    .($this->directoryHits !== [] ? ' ('.implode(', ', $this->directoryHits).')' : '')
                : '✗ Live probe FAILED: '.($this->dfsError ?? 'unknown error');
            if ($this->dfsOk && $this->directoryHits === [] && $this->sampleDomains !== []) {
                $lines[] = '   top result domains: '.implode(', ', $this->sampleDomains);
            }
        }

        $lines[] = $mark($this->failedScanJobs === 0)." Queue: {$this->pendingJobs} pending, {$this->failedScanJobs} failed scan job(s)";
        $lines[] = "• History: {$this->foundDomains} found-domain(s), {$this->statusRows} status row(s)"
            .($this->lastRun !== null ? ", last run {$this->lastRun}" : ', no scan run recorded');

        return $lines;
    }
}
