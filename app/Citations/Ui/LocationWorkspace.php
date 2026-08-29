<?php

namespace App\Citations\Ui;

use App\Citations\CitationApplicability;
use App\Enums\DirectoryScope;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\TenantDirectoryExclusion;

/**
 * View-model for the location citation workspace (§ Citations UI, PR C) — the directory-by-directory work
 * surface for one location. Builds a {@see WorkspaceRow} per applicable directory (excluded ones still appear
 * as "Not relevant"), resolves each chip via {@see CitationChip}, and orders them by the default work priority
 * so a mismatch that actively costs calls sits above a coverage gap. Also returns the stat strip.
 */
final class LocationWorkspace
{
    /** chip key => default sort rank (lower = higher priority). */
    private const RANK = [
        'mismatch' => 0, 'stalled' => 1, 'rejected' => 2, 'missing' => 3,
        'submitted' => 4, 'live' => 5, 'not_scanned' => 6, 'not_relevant' => 7,
    ];

    public function __construct(private readonly CitationApplicability $applicability = new CitationApplicability) {}

    /**
     * @return array{stats: array{live: int, mismatch: int, in_flight: int, missing: int, submittable_missing: int}, rows: list<WorkspaceRow>}
     */
    public function forLocation(Location $location, bool $includeNotRelevant = false): array
    {
        // The full geo/trade-applicable universe, including tenant-excluded directories (shown "Not relevant").
        $universe = $this->applicability->forLocation($location, applyExclusions: false);
        $excluded = TenantDirectoryExclusion::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)->pluck('directory_id')->map('strval')->flip();

        $statuses = CitationStatus::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->get()->keyBy('directory_id');

        $rows = [];
        $stats = ['live' => 0, 'mismatch' => 0, 'in_flight' => 0, 'missing' => 0, 'submittable_missing' => 0];

        foreach ($universe as $dir) {
            $eligible = ! $excluded->has((string) $dir->id);
            $status = $statuses->get($dir->id);
            $chip = CitationChip::for($status, $eligible);

            $submittable = $eligible && (bool) $dir->is_submittable && in_array($chip['key'], ['missing', 'mismatch'], true);
            $rows[] = new WorkspaceRow(
                statusId: $status !== null ? (string) $status->id : null,
                directoryId: (string) $dir->id,
                directoryName: (string) $dir->name,
                homepageUrl: $dir->homepage_url,
                listingUrl: $status?->found_url,
                tierLabel: $dir->tierLabel(),
                isLocal: $dir->scope !== DirectoryScope::National,
                submittable: $submittable,
                chip: $chip,
                napMatchSummary: $this->napSummary($chip['key'], $status),
                lastCheckedAt: $status?->last_scanned_at,
                eligible: $eligible,
                sortRank: self::RANK[$chip['key']] ?? 6,
            );

            match ($chip['key']) {
                'live' => $stats['live']++,
                'mismatch' => $stats['mismatch']++,
                'submitted' => $stats['in_flight']++,
                'missing' => $stats['missing']++,
                default => null,
            };
            if ($chip['key'] === 'missing' && $submittable) {
                $stats['submittable_missing']++;
            }
        }

        $rows = array_values(array_filter($rows, fn (WorkspaceRow $r): bool => $includeNotRelevant || $r->chip['key'] !== 'not_relevant'));
        usort($rows, function (WorkspaceRow $a, WorkspaceRow $b): int {
            // Within a chip group: submittable first, then local before national, then name.
            return [$a->sortRank, $a->submittable ? 0 : 1, $a->isLocal ? 0 : 1, $a->directoryName]
                <=> [$b->sortRank, $b->submittable ? 0 : 1, $b->isLocal ? 0 : 1, $b->directoryName];
        });

        return ['stats' => $stats, 'rows' => $rows];
    }

    private function napSummary(string $chipKey, ?CitationStatus $status): string
    {
        if ($chipKey === 'live') {
            return 'Exact';
        }
        if ($chipKey === 'mismatch' && $status?->mismatch_fields) {
            return implode(', ', array_map('ucfirst', array_keys($status->mismatch_fields)));
        }

        return '—';
    }
}
