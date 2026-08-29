<?php

namespace App\Citations;

use App\Enums\CitationPresence;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\MetricSnapshot;
use Illuminate\Support\Carbon;

/**
 * The Local Presence Score (§ Citations, PR3) — a 0–100 health number per location: how much of the citation
 * coverage the location SHOULD have does it actually have, weighted by directory value so a Yelp listing
 * counts for more than an obscure directory.
 *
 * Score = 100 × Σ(weight × coverage-credit) ÷ Σ(weight over applicable directories). Coverage credit is the
 * presence axis only ({@see CitationPresence::coverageCredit}): a correct listing is full credit; a
 * mismatch counts against coverage (0); a gap is 0. A location with nothing applicable scores 100 (vacuously
 * complete). Snapshots land on the shared metric spine so trends are free.
 */
final class LocalPresenceScore
{
    public function __construct(private readonly CitationApplicability $applicability = new CitationApplicability) {}

    /**
     * @return array{score: int, weighted_covered: float, weighted_applicable: float, applicable: int, covered: int}
     */
    public function forLocation(Location $location): array
    {
        $applicable = $this->applicability->forLocation($location);
        $statuses = CitationStatus::query()
            ->where('location_id', $location->id)
            ->get()
            ->keyBy('directory_id');

        $weightedApplicable = 0.0;
        $weightedCovered = 0.0;
        $coveredCount = 0;

        foreach ($applicable as $dir) {
            $weight = $this->weight($dir);
            $weightedApplicable += $weight;

            // Coverage is presence only: a correct listing counts, a mismatch counts against (0), a gap 0.
            $status = $statuses->get($dir->id);
            $credit = $status?->presence->coverageCredit() ?? 0.0;
            $weightedCovered += $weight * $credit;
            if ($credit >= 1.0) {
                $coveredCount++;
            }
        }

        $score = $weightedApplicable > 0.0
            ? (int) round(100 * $weightedCovered / $weightedApplicable)
            : 100;

        return [
            'score' => $score,
            'weighted_covered' => round($weightedCovered, 2),
            'weighted_applicable' => round($weightedApplicable, 2),
            'applicable' => $applicable->count(),
            'covered' => $coveredCount,
        ];
    }

    /**
     * Snapshot the score onto the shared metric spine (idempotent per month), so the client dashboard and
     * trend widgets read it like any other metric. Returns the computed score payload.
     *
     * @return array{score: int, weighted_covered: float, weighted_applicable: float, applicable: int, covered: int}
     */
    public function snapshot(Location $location, ?Carbon $asOf = null): array
    {
        $result = $this->forLocation($location);
        $asOf ??= Carbon::now();

        MetricSnapshot::query()->updateOrCreate(
            [
                'site_id' => $location->site_id,
                'provider' => 'citations',
                'metric_key' => 'local_presence_score',
                'dimension_type' => 'location',
                'dimension_value' => (string) $location->id,
                'period_grain' => 'month',
                'period_date' => $asOf->copy()->startOfMonth(),
            ],
            [
                'value_numeric' => $result['score'],
                'value_json' => $result,
                'captured_at' => $asOf,
            ],
        );

        return $result;
    }

    /** Directory value weight — SEO value drives it (authority tier as a fallback), floored at 1 so every
     * directory counts at least once. */
    private function weight(Directory $dir): float
    {
        $value = $dir->seoValueFor(null) ?? ($dir->authority_tier * 20);

        return max(1.0, (float) $value);
    }
}
