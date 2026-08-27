<?php

namespace App\GeoGrid;

use App\Models\GeoGridScan;

/**
 * The PR 5 calibration comparison — the instrument that turns "does DataForSEO agree with Local Falcon?" into
 * numbers, so the decision gate (accept the §5 formulas / tune zoom+depth and re-scan / conclude buying Local
 * Falcon's API) is data-driven rather than eyeballed. Pure logic: it takes OUR stored scan points and a Local
 * Falcon point set (same 7×7 grid, same center, same spacing) and reports point-by-point rank agreement,
 * found/not-found agreement, and an aggregate comparison — diagnosed in the spec's order (point-level first,
 * formulas second).
 *
 * The two point sets are aligned by GEOMETRY, matching each of our cells to the nearest Local Falcon point by
 * lat/lng — robust to whatever row/col indexing convention Local Falcon exports (row 0 = north or south, etc.),
 * which is the easiest thing to get wrong when comparing two providers' grids. Local Falcon aggregates are
 * recomputed from its points with OUR formulas ({@see GeoGridMetrics}) so a point-level-agrees-but-aggregates-
 * diverge result cleanly isolates a formula bug (a cheap recompute) from a geometry/zoom bug (a re-scan).
 */
final class GeoGridCalibration
{
    /** Spec acceptance thresholds (§6) — overridable per run. */
    public const MAX_MEDIAN_ABS_DIFF = 1.0;

    public const MIN_FOUND_AGREEMENT = 0.90;

    public function __construct(private readonly GeoGridMetrics $metrics) {}

    /**
     * Compare a stored scan against a Local Falcon point set.
     *
     * @param  list<array{lat: float, lng: float, rank: ?int, row?: int, col?: int}>  $localFalcon
     * @return array{
     *   total_points: int, matched: int, both_found: int,
     *   median_abs_diff: ?float, mean_abs_diff: ?float, found_agreement: float,
     *   aggregates: array{ours: array<string, ?float>, local_falcon: array<string, ?float>},
     *   thresholds: array{max_median: float, min_agreement: float},
     *   passes: array{point_level: bool, coverage: bool},
     *   verdict: string, diagnosis: string,
     *   points: list<array{row: int, col: int, ours: ?int, local_falcon: ?int, abs_diff: ?int, agree: bool, matched: bool}>
     * }
     */
    public function compare(GeoGridScan $scan, array $localFalcon, ?float $maxMedian = null, ?float $minAgreement = null): array
    {
        $maxMedian ??= self::MAX_MEDIAN_ABS_DIFF;
        $minAgreement ??= self::MIN_FOUND_AGREEMENT;
        $depthCap = (int) $scan->depth_cap;

        // Half a grid step, in degrees, as the max match distance — beyond it a Local Falcon point isn't this
        // cell. spacing_miles / 69 is one lat-degree step; half of it separates adjacent cells' territories.
        $tolerance = ((float) $scan->spacing_miles / 69.0) * 0.6;

        $rows = [];
        $absDiffs = [];
        $agreeCount = 0;
        $matched = 0;
        $lfRanksByCell = [];   // aligned to OUR cells, for the Local Falcon aggregate recompute

        foreach ($scan->points as $p) {
            $ours = $p->rank !== null ? (int) $p->rank : null;
            $isMatched = false;
            $lf = $this->nearestRank((float) $p->lat, (float) $p->lng, $localFalcon, $tolerance, $isMatched);
            if ($isMatched) {
                $matched++;
            }
            $lfRanksByCell[] = (object) ['rank' => $lf];

            $bothFound = $ours !== null && $lf !== null;
            $absDiff = $bothFound ? abs($ours - $lf) : null;
            if ($absDiff !== null) {
                $absDiffs[] = $absDiff;
            }
            // Found/not-found agreement: both found, or both not-found, at this cell.
            $agree = ($ours === null) === ($lf === null);
            if ($agree) {
                $agreeCount++;
            }

            $rows[] = [
                'row' => (int) $p->row, 'col' => (int) $p->col,
                'ours' => $ours, 'local_falcon' => $lf, 'abs_diff' => $absDiff, 'agree' => $agree, 'matched' => $isMatched,
            ];
        }

        $total = count($rows);
        $foundAgreement = $total > 0 ? $agreeCount / $total : 0.0;
        $median = $this->median($absDiffs);
        $mean = $absDiffs === [] ? null : round(array_sum($absDiffs) / count($absDiffs), 2);

        [$verdict, $diagnosis, $pointPass, $coveragePass] = $this->verdict($median, $foundAgreement, $maxMedian, $minAgreement);

        return [
            'total_points' => $total,
            'matched' => $matched,
            'both_found' => count($absDiffs),
            'median_abs_diff' => $median,
            'mean_abs_diff' => $mean,
            'found_agreement' => round($foundAgreement, 4),
            'aggregates' => [
                'ours' => [
                    'atrp' => $this->num($scan->atrp), 'arp' => $this->num($scan->arp),
                    'solv' => $this->num($scan->solv), 'found_rate' => $this->num($scan->found_rate),
                ],
                'local_falcon' => $this->metrics->compute($lfRanksByCell, $depthCap),
            ],
            'thresholds' => ['max_median' => $maxMedian, 'min_agreement' => $minAgreement],
            'passes' => ['point_level' => $pointPass, 'coverage' => $coveragePass],
            'verdict' => $verdict,
            'diagnosis' => $diagnosis,
            'points' => $rows,
        ];
    }

    /**
     * The Local Falcon rank at the point nearest (lat,lng) within tolerance, or null (no match, or matched a
     * not-found point). Sets $matched to whether any LF point fell within tolerance at all.
     *
     * @param  list<array{lat: float, lng: float, rank: ?int, row?: int, col?: int}>  $localFalcon
     */
    private function nearestRank(float $lat, float $lng, array $localFalcon, float $tolerance, bool &$matched): ?int
    {
        $best = null;
        $bestDist = INF;
        foreach ($localFalcon as $lf) {
            $d = (($lf['lat'] - $lat) ** 2) + (($lf['lng'] - $lng) ** 2);
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $lf;
            }
        }

        $matched = $best !== null && sqrt($bestDist) <= $tolerance;

        return $matched ? ($best['rank'] ?? null) : null;
    }

    /**
     * @return array{0: string, 1: string, 2: bool, 3: bool} [verdict, diagnosis, pointLevelPass, coveragePass]
     */
    private function verdict(?float $median, float $agreement, float $maxMedian, float $minAgreement): array
    {
        $coveragePass = $agreement >= $minAgreement;
        // No both-found points means point-level can't be judged — treat as a fail to force a re-look.
        $pointPass = $median !== null && $median <= $maxMedian;

        $agreePct = round($agreement * 100, 1);
        $medStr = $median === null ? 'n/a (no cells found on both)' : (string) $median;

        if (! $coveragePass) {
            return ['tune',
                "Found/not-found agreement {$agreePct}% is below the {$this->pct($minAgreement)} floor — coverage diverges. Geometry or (more likely) zoom/depth is wrong. Tune zoom & depth and re-scan; no formula change fixes a coverage gap.",
                $pointPass, false];
        }
        if (! $pointPass) {
            return ['tune',
                "Coverage agrees ({$agreePct}%) but the median absolute rank difference is {$medStr} (> {$maxMedian}) — point-level ranks disagree. That's a geometry/zoom problem, not a formula one; tune and re-scan. Diagnose in this order — no §5 formula rescues disagreeing points.",
                false, true];
        }

        return ['accept',
            "Point-level agreement holds (median abs diff {$medStr} ≤ {$maxMedian}, coverage {$agreePct}% ≥ {$this->pct($minAgreement)}). Accept the §5 formulas — then check the aggregate row: if ATRP/SoLV still diverge despite matching points, the formula (non-found handling) is off and is a cheap recompute, not a re-scan.",
            true, true];
    }

    /** @param list<int> $values */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : round(($values[$mid - 1] + $values[$mid]) / 2, 2);
    }

    private function pct(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio * 100, 1), '0'), '.').'%';
    }

    private function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
