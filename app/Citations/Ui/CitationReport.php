<?php

namespace App\Citations\Ui;

use App\Citations\CitationApplicability;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Models\CitationStatus;
use App\Models\Location;
use App\Models\Scopes\SiteScope;

/**
 * Builds the client-readable {@see CitationReportData} for a location (§ Citations UI, PR E). Same records as
 * the operator workspace, translated to a client reading level: correct / wrong / being-added / available
 * counts, the plain-language corrections, and the available directory list. Built as a standalone view-model so
 * the report can be lifted into the client /portal later without a rewrite.
 */
final class CitationReport
{
    public function __construct(private readonly CitationApplicability $applicability = new CitationApplicability) {}

    public function forLocation(Location $location): CitationReportData
    {
        $eligible = $this->applicability->forLocation($location);
        $statuses = CitationStatus::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->get()->keyBy('directory_id');

        $listedCorrectly = $wrong = $beingAdded = $available = 0;
        $corrections = [];
        $availableNames = [];

        foreach ($eligible as $dir) {
            $status = $statuses->get($dir->id);
            $isSubmitted = $status?->lifecycle === CitationLifecycleState::Submitted;
            $presence = $status?->presence;

            if ($presence === CitationPresence::PresentMismatch) {
                $wrong++;
                $corrections[] = [
                    'directory' => (string) $dir->name,
                    'fields' => $this->correctionFields($status),
                ];
            } elseif ($isSubmitted) {
                $beingAdded++;
            } elseif ($presence === CitationPresence::PresentMatch) {
                $listedCorrectly++;
            } else {
                $available++;
                $availableNames[] = (string) $dir->name;
            }
        }

        return new CitationReportData(
            locationName: (string) $location->name,
            listedCorrectly: $listedCorrectly,
            wrongInformation: $wrong,
            beingAdded: $beingAdded,
            stillAvailable: $available,
            corrections: $corrections,
            available: $availableNames,
        );
    }

    /**
     * @return list<array{field: string, found: string, expected: string}>
     */
    private function correctionFields(?CitationStatus $status): array
    {
        $out = [];
        foreach ($status?->mismatch_fields ?? [] as $field => $vals) {
            $out[] = [
                'field' => ucfirst((string) $field),
                'found' => (string) ($vals['found'] ?? ''),
                'expected' => (string) ($vals['expected'] ?? ''),
            ];
        }

        return $out;
    }
}
