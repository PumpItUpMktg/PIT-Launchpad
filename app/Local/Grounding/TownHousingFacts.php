<?php

namespace App\Local\Grounding;

use App\Models\CensusHousing;

/**
 * A town's housing stock, said in plain sentences the drafter can work into prose.
 *
 * Every sentence is a stored Census count, rounded and attributed — nothing is inferred and nothing is
 * implied about a reader's own house. In particular we state the share of OLD HOUSING STOCK, never what
 * that stock is made of: "most homes here predate 1960" is the Census; "so you have fieldstone
 * foundations" is a guess about a specific building, and the drafter is told elsewhere never to invent
 * local detail. The trade-specific reading is the writer's job, grounded on the number.
 *
 * A number is stated only when it carries information: a share near half says nothing worth a sentence,
 * so the low and high ends are reported and the middle is left out.
 */
final class TownHousingFacts
{
    /** Below this the stock is notably new; above it, notably old. */
    private const OLD_STOCK = 0.35;

    private const NEW_STOCK = 0.10;

    /** Owner-occupancy and single-family share are only worth saying at the ends. */
    private const HIGH = 0.75;

    private const LOW = 0.45;

    /**
     * @return list<string>
     */
    public function for(?CensusHousing $housing): array
    {
        if ($housing === null) {
            return [];
        }

        $town = trim((string) $housing->name) !== '' ? trim((string) $housing->name) : 'this town';
        $year = (int) $housing->acs_year;
        $facts = [];

        if ($housing->median_year_built !== null && $housing->median_year_built > 1800) {
            $facts[] = sprintf('The median home in %s was built in %d (Census ACS %d).', $town, $housing->median_year_built, $year);
        }

        $old = $housing->pre1960Share();
        if ($old !== null && $old >= self::OLD_STOCK) {
            $facts[] = sprintf('About %d%% of the housing stock in %s was built before 1960.', (int) round($old * 100), $town);
        } elseif ($old !== null && $old <= self::NEW_STOCK && $housing->median_year_built !== null && $housing->median_year_built >= 1980) {
            $facts[] = sprintf('Housing in %s is mostly newer — under %d%% of it predates 1960.', $town, max(1, (int) ceil($old * 100)));
        }

        $owned = $housing->ownerOccupiedShare();
        if ($owned !== null && $owned >= self::HIGH) {
            $facts[] = sprintf('%d%% of occupied homes in %s are owner-occupied.', (int) round($owned * 100), $town);
        } elseif ($owned !== null && $owned <= self::LOW) {
            $facts[] = sprintf('Only %d%% of occupied homes in %s are owner-occupied — the rest are rentals.', (int) round($owned * 100), $town);
        }

        $single = $housing->singleFamilyShare();
        if ($single !== null && $single >= self::HIGH) {
            $facts[] = sprintf('%d%% of homes in %s are single-family houses.', (int) round($single * 100), $town);
        } elseif ($single !== null && $single <= self::LOW) {
            $facts[] = sprintf('%s is only %d%% single-family housing; much of it is multi-unit.', $town, (int) round($single * 100));
        }

        return $facts;
    }

    /** The stored row for a page's Census GEOID, or null when the town has never been fetched. */
    public function row(?string $geoId): ?CensusHousing
    {
        $geoId = trim((string) $geoId);

        return $geoId === '' ? null : CensusHousing::query()->where('geo_id', $geoId)->first();
    }
}
