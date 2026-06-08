<?php

namespace App\Client;

use App\Enums\BeatabilityLane;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\RefreshEvent;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;

/**
 * Organic position-over-time for a keyword, with RefreshEvent markers overlaid
 * as OBSERVED CORRELATION ONLY — "content refreshed here, position moved there".
 *
 * Honest framing (hard constraint): the markers are plain dates the UI annotates
 * onto the trend. There is no causal claim, and no stored/computed ROI-attribution
 * field anywhere in this output — the data speaks for itself.
 */
class PositionTrends
{
    /**
     * @return array{
     *     series: list<array{date: string, rank: int|null}>,
     *     refresh_markers: list<array{date: string}>,
     *     standings: array{primary: int|null, secondary: int|null, tertiary: int|null, as_of: string|null}
     * }
     */
    public function forKeyword(Keyword $keyword): array
    {
        /** @var Collection<int, PositionSnapshot> $organic */
        $organic = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('keyword_id', $keyword->id)
            ->where('lane', BeatabilityLane::Organic->value)
            ->orderBy('captured_at')
            ->get();

        return [
            'series' => $organic
                ->map(fn (PositionSnapshot $s) => ['date' => (string) $s->captured_at?->toDateString(), 'rank' => $s->rank])
                ->all(),
            // Correlation annotations — dates only. Never a causal/ROI figure.
            'refresh_markers' => $this->refreshMarkers($keyword),
            'standings' => $this->standings($organic),
        ];
    }

    /**
     * @return list<array{date: string}>
     */
    private function refreshMarkers(Keyword $keyword): array
    {
        if ($keyword->target_content_id === null) {
            return [];
        }

        return RefreshEvent::withoutGlobalScope(SiteScope::class)
            ->where('content_id', $keyword->target_content_id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (RefreshEvent $e) => ['date' => (string) $e->created_at?->toDateString()])
            ->all();
    }

    /**
     * The latest three distinct ranking positions (primary/secondary/tertiary).
     *
     * @param  Collection<int, PositionSnapshot>  $organic
     * @return array{primary: int|null, secondary: int|null, tertiary: int|null, as_of: string|null}
     */
    private function standings(Collection $organic): array
    {
        $latest = $organic->last();
        $ranks = $organic
            ->reverse()
            ->map(fn (PositionSnapshot $s) => $s->rank)
            ->filter(fn (?int $r) => $r !== null)
            ->unique()
            ->take(3)
            ->values();

        return [
            'primary' => $ranks->get(0),
            'secondary' => $ranks->get(1),
            'tertiary' => $ranks->get(2),
            'as_of' => $latest?->captured_at?->toDateString(),
        ];
    }
}
