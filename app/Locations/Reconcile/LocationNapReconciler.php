<?php

namespace App\Locations\Reconcile;

use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\ActiveLocationScope;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Heals the two-unlinked-rows NAP bug: a physical location that exists as BOTH a bare intake row
 * (name/address/phone only) and a GBP-enriched row (place_id + address_components + lat/lng + hours).
 * The location page pins to one and renders whatever it has, so the NAP loses city/hours/coords.
 *
 * The reconcile pairs a bare row with its enriched sibling (high-precision match only — phone or
 * address+name, never name alone, exactly one candidate each side), picks a SURVIVOR (the row a live
 * hub page is pinned to, so the URL never changes; else the enriched row), back-fills the union of GBP
 * data onto the survivor WITHOUT overwriting operator-entered values, re-points every Content pin from
 * the duplicate to the survivor, and TOMBSTONES the duplicate (`merged_into_id` → survivor, place_id
 * cleared). Non-destructive: the retired row stays in the table (hidden by {@see ActiveLocationScope},
 * reversible by nulling the column) and never grows a duplicate hub page.
 *
 * A second pass folds SAME-KIND duplicates — one GBP listing imported twice (identical place_id), or two
 * bare rows for one shop (same phone and the same name or address) — under the same exactly-two rule,
 * keeping the row a live hub is pinned to (else the one with more pages, then the richer NAP, then the
 * older row).
 *
 * Dry-run computes the plan without touching anything; apply runs it in one transaction per merge.
 */
class LocationNapReconciler
{
    /** GBP/geo fields the survivor inherits from the duplicate when the survivor's own value is empty. */
    private const BACKFILL_FIELDS = [
        'place_id', 'gbp_url', 'lat', 'lng', 'address_components', 'hours', 'phone', 'address', 'email',
        'served_towns', 'primary_category', 'latitude', 'longitude', 'geocoded_at', 'coverage_radius',
        'home_county_geoid', 'county_geoids', 'grounding_cache',
    ];

    /**
     * @return list<LocationMerge>
     */
    public function reconcile(Site $site, bool $apply): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        [$bare, $enriched] = $locations->partition(fn (Location $l) => $l->place_id === null || trim((string) $l->place_id) === '');

        $usedEnriched = [];
        /** @var array<string, true> $claimed every row a pass-1 fold touched (survivor or dupe) */
        $claimed = [];
        $merges = [];

        foreach ($bare as $bareRow) {
            $match = $this->matchEnriched($bareRow, $enriched, $usedEnriched);
            if ($match === null) {
                continue;
            }
            [$enrichedRow, $matchedOn] = $match;
            $usedEnriched[$enrichedRow->id] = true;
            $claimed[$bareRow->id] = true;
            $claimed[$enrichedRow->id] = true;

            // Survivor = whichever row a live hub page is already pinned to (keep the URL); else the
            // richer enriched row. The other is the duplicate that gets folded in.
            $survivor = $this->hasLiveHub($bareRow) ? $bareRow : $enrichedRow;
            $dupe = $survivor->is($bareRow) ? $enrichedRow : $bareRow;

            $merges[] = $this->fold($survivor, $dupe, $matchedOn, $apply);
        }

        // Pass 2 — same-KIND duplicates: the same GBP listing imported twice (identical place_id), or two
        // bare rows for one shop (same phone AND the same name or address). Pass 1 cannot see these —
        // it only pairs a bare row with an enriched one — so a portfolio import run twice left "Trooper"
        // and "Hackensack" as two live rows each. Same precision rule: a group of EXACTLY two folds;
        // three or more is ambiguous and left for a person.
        foreach ($this->sameKindPairs($locations->reject(fn (Location $l): bool => isset($claimed[$l->id]))) as [$a, $b, $matchedOn]) {
            [$survivor, $dupe] = $this->rankSurvivor($a, $b);
            $merges[] = $this->fold($survivor, $dupe, $matchedOn, $apply);
        }

        return $merges;
    }

    /** Plan (or apply) one fold and describe it. */
    private function fold(Location $survivor, Location $dupe, string $matchedOn, bool $apply): LocationMerge
    {
        $backfilled = $this->backfillFields($survivor, $dupe);

        $repointed = $apply
            ? $this->apply($survivor, $dupe, $backfilled)
            : $this->countPins($dupe);

        return new LocationMerge(
            survivorId: $survivor->id,
            survivorName: (string) $survivor->name,
            dupeId: $dupe->id,
            dupeName: (string) $dupe->name,
            matchedOn: $matchedOn,
            backfilled: array_keys($backfilled),
            contentRepointed: $repointed,
        );
    }

    /**
     * Pairs of same-kind rows that are one shop: two enriched rows with an identical place_id, or two bare
     * rows with identical phone digits and the same normalized name or address. A row joins at most one
     * pair; a key shared by three or more rows is skipped as ambiguous.
     *
     * @param  Collection<int, Location>  $rows
     * @return list<array{0: Location, 1: Location, 2: string}>
     */
    private function sameKindPairs(Collection $rows): array
    {
        $pairs = [];
        $taken = [];

        // Two ENRICHED rows are one shop only when they are the same Google listing (identical place_id):
        // two distinct listings on one phone or address are two listings, never guessed into one. The
        // phone/name/address keys apply to BARE rows only, where no listing id exists to be sure with.
        $isBare = fn (Location $l): bool => trim((string) $l->place_id) === '';
        $keyed = [
            'place_id' => fn (Location $l): string => trim((string) $l->place_id),
            'phone+name' => fn (Location $l): string => ! $isBare($l) || $this->digits($l->phone) === '' ? '' : $this->digits($l->phone).'|'.$this->normName($l->name),
            'phone+address' => fn (Location $l): string => ! $isBare($l) || $this->digits($l->phone) === '' || $this->normAddress($l->address) === '' ? '' : $this->digits($l->phone).'|'.$this->normAddress($l->address),
        ];

        foreach ($keyed as $matchedOn => $keyOf) {
            $groups = $rows
                ->reject(fn (Location $l): bool => isset($taken[$l->id]))
                ->groupBy(fn (Location $l): string => $keyOf($l))
                ->filter(fn ($group, $key): bool => (string) $key !== ''); // rows with no key never pair

            foreach ($groups as $group) {
                if ($group->count() !== 2) {
                    continue; // singleton, or ambiguous (3+) — never guess
                }
                [$a, $b] = $group->values()->all();
                $taken[$a->id] = true;
                $taken[$b->id] = true;
                $pairs[] = [$a, $b, $matchedOn];
            }
        }

        return $pairs;
    }

    /**
     * Which of two same-kind rows survives: the one a LIVE hub page is pinned to (the URL never changes);
     * else the one more pages are pinned to; else the richer NAP; else the older row.
     *
     * @return array{0: Location, 1: Location} [survivor, dupe]
     */
    private function rankSurvivor(Location $a, Location $b): array
    {
        $score = fn (Location $l): array => [
            $this->hasLiveHub($l) ? 1 : 0,
            $this->countPins($l),
            count(array_filter(self::BACKFILL_FIELDS, fn (string $f): bool => ! $this->isEmpty($l->getAttribute($f)))),
            -(int) ($l->created_at?->getTimestamp() ?? PHP_INT_MAX),
        ];

        return $score($a) >= $score($b) ? [$a, $b] : [$b, $a];
    }

    /**
     * The single enriched sibling for a bare row, or null. Phone-equality (digits) wins; else a
     * normalized address match corroborated by name-token overlap. Ambiguous (2+ candidates) → null.
     *
     * @param  Collection<int, Location>  $enriched
     * @param  array<string, true>  $used  enriched ids already claimed by an earlier bare row
     * @return array{0: Location, 1: string}|null
     */
    private function matchEnriched(Location $bare, $enriched, array $used): ?array
    {
        $available = $enriched->reject(fn (Location $e) => isset($used[$e->id]));

        $barePhone = $this->digits($bare->phone);
        if ($barePhone !== '') {
            $byPhone = $available->filter(fn (Location $e) => $this->digits($e->phone) === $barePhone)->values();
            if ($byPhone->count() === 1) {
                return [$byPhone->first(), 'phone'];
            }
            if ($byPhone->count() > 1) {
                return null; // ambiguous — leave for manual review
            }
        }

        $bareAddr = $this->normAddress($bare->address);
        $bareName = $this->normName($bare->name);
        if ($bareAddr !== '') {
            $byAddr = $available->filter(function (Location $e) use ($bareAddr, $bareName) {
                return $this->normAddress($e->address) === $bareAddr
                    && $this->nameOverlap($bareName, $this->normName($e->name));
            })->values();
            if ($byAddr->count() === 1) {
                return [$byAddr->first(), 'address + name'];
            }
        }

        return null;
    }

    /**
     * Fill the survivor's EMPTY fields from the duplicate (operator-entered values are never
     * overwritten). Returns the changed field => new value map (also mutates $survivor in memory so
     * apply() can persist it).
     *
     * @return array<string, mixed>
     */
    private function backfillFields(Location $survivor, Location $dupe): array
    {
        $changes = [];
        foreach (self::BACKFILL_FIELDS as $field) {
            if (! $this->isEmpty($survivor->getAttribute($field)) || $this->isEmpty($dupe->getAttribute($field))) {
                continue;
            }
            $changes[$field] = $dupe->getAttribute($field);
            $survivor->setAttribute($field, $dupe->getAttribute($field));
        }

        return $changes;
    }

    /**
     * Persist a merge in one transaction: save the back-filled survivor, re-point every Content pin,
     * and tombstone the duplicate (clearing its place_id so no duplicate place_id survives).
     *
     * @param  array<string, mixed>  $backfilled
     */
    private function apply(Location $survivor, Location $dupe, array $backfilled): int
    {
        return DB::transaction(function () use ($survivor, $dupe): int {
            if ($survivor->isDirty()) {
                $survivor->save();
            }

            $repointed = Content::withoutGlobalScope(SiteScope::class)
                ->where('location_id', $dupe->id)
                ->update(['location_id' => $survivor->id]);

            Content::withoutGlobalScope(SiteScope::class)
                ->where('parent_location_id', $dupe->id)
                ->update(['parent_location_id' => $survivor->id]);

            $dupe->forceFill(['merged_into_id' => $survivor->id, 'place_id' => null])->save();

            return $repointed;
        });
    }

    private function countPins(Location $dupe): int
    {
        return Content::withoutGlobalScope(SiteScope::class)->where('location_id', $dupe->id)->count();
    }

    private function hasLiveHub(Location $location): bool
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('wp_post_id')
            ->exists();
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function normAddress(?string $value): string
    {
        $v = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower((string) $value)) ?? '';

        return trim(preg_replace('/\s+/', ' ', $v) ?? '');
    }

    private function normName(?string $value): string
    {
        return $this->normAddress($value);
    }

    /** Do two normalized names share a meaningful token (guards the address match against a coincidence)? */
    private function nameOverlap(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        $stop = ['the', 'and', 'llc', 'inc', 'co', 'office', 'main', 'of', 'a'];
        $ta = array_diff(explode(' ', $a), $stop);
        $tb = array_diff(explode(' ', $b), $stop);

        return array_intersect($ta, $tb) !== [];
    }

    private function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return $value === null || (is_string($value) && trim($value) === '');
    }
}
