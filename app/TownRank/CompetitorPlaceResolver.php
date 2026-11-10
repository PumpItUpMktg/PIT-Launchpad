<?php

namespace App\TownRank;

use App\Integrations\Places\PlacesProvider;
use App\Jobs\ResolveCompetitorPlaces;
use App\Models\CompetitorPlace;
use Illuminate\Support\Carbon;

/**
 * Coordinates for the competitors a coverage scan saw in the pack. `coords()` answers from the table and
 * queues a lookup for the ids it does not know (one Places details call each, once ever; a failed lookup
 * is retried after `retry_days`). `resolve()` is the worker side.
 */
final class CompetitorPlaceResolver
{
    public function __construct(private readonly PlacesProvider $places) {}

    /**
     * @param  list<string>  $placeIds
     * @return array<string, array{lat: float, lng: float, name: string|null}> the ids we know
     */
    public function coords(array $placeIds, bool $queueMissing = true): array
    {
        $ids = array_values(array_unique(array_filter($placeIds, fn ($id): bool => is_string($id) && $id !== '')));
        if ($ids === []) {
            return [];
        }
        $rows = CompetitorPlace::query()->whereIn('place_id', $ids)->get()->keyBy('place_id');
        $out = [];
        $missing = [];
        $retryBefore = Carbon::now()->subDays((int) config('launchpad.town_rank.gbp_reach.retry_days', 30));
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if ($row !== null && $row->lat !== null && $row->lng !== null) {
                $out[$id] = ['lat' => (float) $row->lat, 'lng' => (float) $row->lng, 'name' => $row->name];
            } elseif ($row === null || $row->failed_at === null || $row->failed_at->lessThan($retryBefore)) {
                $missing[] = $id;
            }
        }
        if ($missing !== [] && $queueMissing) {
            ResolveCompetitorPlaces::dispatch($missing);
        }

        return $out;
    }

    /**
     * Look the ids up through Places and store what came back.
     *
     * @param  list<string>  $placeIds
     * @return array{resolved: int, failed: int}
     */
    public function resolve(array $placeIds): array
    {
        $out = ['resolved' => 0, 'failed' => 0];
        foreach (array_values(array_unique($placeIds)) as $id) {
            $existing = CompetitorPlace::query()->find($id);
            if ($existing !== null && $existing->lat !== null) {
                continue;
            }
            $details = null;
            try {
                $details = $this->places->details($id);
            } catch (\Throwable $e) {
                report($e);
            }
            if ($details !== null && $details->lat !== null && $details->lng !== null) {
                CompetitorPlace::query()->updateOrCreate(['place_id' => $id], ['name' => $details->name, 'lat' => $details->lat, 'lng' => $details->lng, 'resolved_at' => Carbon::now(), 'failed_at' => null]);
                $out['resolved']++;
            } else {
                CompetitorPlace::query()->updateOrCreate(['place_id' => $id], ['failed_at' => Carbon::now()]);
                $out['failed']++;
            }
        }

        return $out;
    }
}
