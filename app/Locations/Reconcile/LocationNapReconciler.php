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
 * `$keep` overrides the survivor rule for an operator who has decided: 'newest' / 'oldest' keeps that row
 * of every pair by created_at (pages and history still fold onto it). Besides Content pins, a fold carries
 * the duplicate's HISTORY to the survivor — grid scans, the scan plan (when the survivor has none), GBP
 * profile/reviews, reviews, social channels, outreach, link plans, shared-phone ownership, citation
 * attributions and coverage-area sources — so nothing the duplicate learned is orphaned on a hidden row.
 * Its own citation rows (statuses / runs / NAP profile) stay with it: the survivor has its own.
 *
 * Dry-run computes the plan without touching anything; apply runs it in one transaction per merge.
 */
class LocationNapReconciler
{
    public const KEEP_NEWEST = 'newest';

    public const KEEP_OLDEST = 'oldest';

    /**
     * Plain location references a fold re-points from the duplicate to the survivor: table => column.
     * (coverage_scan_plans is handled apart — one per location; coverage_areas carries a json list.)
     */
    private const HISTORY_REFERENCES = [
        'geo_grid_scans' => 'location_id',
        'gbp_profiles' => 'location_id',
        'gbp_reviews' => 'location_id',
        'reviews' => 'location_id',
        'social_channels' => 'location_id',
        'outreach_kits' => 'location_id',
        'outreach_emails' => 'location_id',
        'link_plans' => 'market_location_id',
        'tenant_shared_phones' => 'owning_location_id',
        'citation_statuses' => 'attributed_location_id',
    ];

    /** GBP/geo fields the survivor inherits from the duplicate when the survivor's own value is empty. */
    private const BACKFILL_FIELDS = [
        'place_id', 'gbp_url', 'lat', 'lng', 'address_components', 'hours', 'phone', 'address', 'email',
        'served_towns', 'primary_category', 'latitude', 'longitude', 'geocoded_at', 'coverage_radius',
        'home_county_geoid', 'county_geoids', 'grounding_cache',
    ];

    /**
     * @return list<LocationMerge>
     */
    public function reconcile(Site $site, bool $apply, ?string $keep = null): array
    {
        if ($keep !== null && ! in_array($keep, [self::KEEP_NEWEST, self::KEEP_OLDEST], true)) {
            throw new \InvalidArgumentException("keep must be 'newest' or 'oldest', got [{$keep}].");
        }

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
            [$survivor, $dupe] = $this->preferred($survivor, $dupe, $keep);

            $merges[] = $this->fold($survivor, $dupe, $matchedOn, $apply);
        }

        // Pass 2 — same-KIND duplicates: the same GBP listing imported twice (identical place_id), or two
        // bare rows for one shop (same phone AND the same name or address). Pass 1 cannot see these —
        // it only pairs a bare row with an enriched one — so a portfolio import run twice left "Trooper"
        // and "Hackensack" as two live rows each. Same precision rule: a group of EXACTLY two folds;
        // three or more is ambiguous and left for a person.
        foreach ($this->sameKindPairs($locations->reject(fn (Location $l): bool => isset($claimed[$l->id]))) as [$a, $b, $matchedOn]) {
            [$survivor, $dupe] = $this->rankSurvivor($a, $b);
            [$survivor, $dupe] = $this->preferred($survivor, $dupe, $keep);
            $merges[] = $this->fold($survivor, $dupe, $matchedOn, $apply);
        }

        return $merges;
    }

    /** Plan (or apply) one fold and describe it. */
    private function fold(Location $survivor, Location $dupe, string $matchedOn, bool $apply): LocationMerge
    {
        $backfilled = $this->backfillFields($survivor, $dupe);

        [$repointed, $history] = $apply
            ? $this->apply($survivor, $dupe)
            : [$this->countPins($dupe), $this->countHistory($survivor, $dupe)];

        return new LocationMerge(
            survivorId: $survivor->id,
            survivorName: (string) $survivor->name,
            dupeId: $dupe->id,
            dupeName: (string) $dupe->name,
            matchedOn: $matchedOn,
            backfilled: array_keys($backfilled),
            contentRepointed: $repointed,
            historyRepointed: $history,
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
     * The operator's override: with `$keep`, the newest (or oldest) row of the pair survives whatever the
     * ranking said; a tie on created_at keeps the ranked choice.
     *
     * @return array{0: Location, 1: Location} [survivor, dupe]
     */
    private function preferred(Location $survivor, Location $dupe, ?string $keep): array
    {
        if ($keep === null) {
            return [$survivor, $dupe];
        }
        $s = (int) ($survivor->created_at?->getTimestamp() ?? 0);
        $d = (int) ($dupe->created_at?->getTimestamp() ?? 0);
        $swap = $keep === self::KEEP_NEWEST ? $d > $s : $d < $s;

        return $swap ? [$dupe, $survivor] : [$survivor, $dupe];
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
     * Persist a merge in one transaction: save the back-filled survivor, re-point every Content pin and
     * the duplicate's history, and tombstone the duplicate (clearing its place_id so no duplicate
     * place_id survives).
     *
     * @return array{0: int, 1: int} [content pins re-pointed, history rows re-pointed]
     */
    private function apply(Location $survivor, Location $dupe): array
    {
        return DB::transaction(function () use ($survivor, $dupe): array {
            if ($survivor->isDirty()) {
                $survivor->save();
            }

            $repointed = Content::withoutGlobalScope(SiteScope::class)
                ->where('location_id', $dupe->id)
                ->update(['location_id' => $survivor->id]);

            Content::withoutGlobalScope(SiteScope::class)
                ->where('parent_location_id', $dupe->id)
                ->update(['parent_location_id' => $survivor->id]);

            $history = $this->repointHistory($survivor, $dupe);

            $dupe->forceFill(['merged_into_id' => $survivor->id, 'place_id' => null])->save();

            return [$repointed, $history];
        });
    }

    /**
     * Carry the duplicate's history to the survivor: every plain reference, the scan plan when the
     * survivor has none of its own (one per location), and the coverage areas that list the duplicate
     * as a source. Returns the number of rows changed.
     */
    private function repointHistory(Location $survivor, Location $dupe): int
    {
        $n = 0;
        foreach (self::HISTORY_REFERENCES as $table => $column) {
            $n += DB::table($table)->where($column, $dupe->id)->update([$column => $survivor->id]);
        }

        $survivorHasPlan = DB::table('coverage_scan_plans')->where('location_id', $survivor->id)->exists();
        $n += $survivorHasPlan
            ? 0
            : DB::table('coverage_scan_plans')->where('location_id', $dupe->id)->update(['location_id' => $survivor->id]);

        foreach ($this->coverageAreasSourcing($dupe) as $row) {
            $ids = array_values(array_unique(array_map(
                fn ($id): string => (string) $id === $dupe->id ? $survivor->id : (string) $id,
                $this->decodeIds($row->source_location_ids),
            )));
            $n += DB::table('coverage_areas')->where('id', $row->id)->update(['source_location_ids' => json_encode($ids)]);
        }

        return $n;
    }

    /** What repointHistory() WOULD change — the dry-run figure. */
    private function countHistory(Location $survivor, Location $dupe): int
    {
        $n = 0;
        foreach (self::HISTORY_REFERENCES as $table => $column) {
            $n += DB::table($table)->where($column, $dupe->id)->count();
        }
        if (! DB::table('coverage_scan_plans')->where('location_id', $survivor->id)->exists()) {
            $n += DB::table('coverage_scan_plans')->where('location_id', $dupe->id)->count();
        }

        return $n + count($this->coverageAreasSourcing($dupe));
    }

    /**
     * Coverage areas on the duplicate's site whose source list names it (decoded in PHP — the json column
     * has no portable contains() across PostgreSQL and SQLite).
     *
     * @return list<object{id: string, source_location_ids: mixed}>
     */
    private function coverageAreasSourcing(Location $dupe): array
    {
        return DB::table('coverage_areas')
            ->where('site_id', $dupe->site_id)
            ->whereNotNull('source_location_ids')
            ->get(['id', 'source_location_ids'])
            ->filter(fn (object $row): bool => in_array($dupe->id, $this->decodeIds($row->source_location_ids), true))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function decodeIds(mixed $json): array
    {
        $ids = is_string($json) ? json_decode($json, true) : $json;

        return is_array($ids) ? array_values(array_map('strval', $ids)) : [];
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
