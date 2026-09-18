<?php

namespace App\Local\Grounding;

use App\Models\TownSoilDrainage;

/**
 * What the ground under a town does with water, from the USDA survey.
 *
 * This is the clay-versus-sand variable, and it is the most directly useful thing we hold for a
 * basement trade: ground classed somewhat poorly, poorly or very poorly drained holds water against a
 * foundation, and ground that drains freely does not. Both facts are worth saying; the middle is not.
 *
 * It describes the TOWN'S GROUND, never a reader's lot. Drainage is mapped at survey scale, so a
 * well-drained property inside a poorly-drained township is entirely ordinary — the copy may say what
 * the town sits on and must not tell anyone what is under their own house.
 *
 * "Not surveyed" is kept apart from "drains well": the survey does not cover every acre, and silence is
 * not a finding.
 */
final class TownSoilFacts
{
    /** Above this share of poorly-draining ground the town is worth describing as wet. */
    private const WET = 0.40;

    /** Below it, worth describing as free-draining. */
    private const DRY = 0.10;

    /**
     * @return list<string>
     */
    public function for(?TownSoilDrainage $soil): array
    {
        if ($soil === null || ! $soil->surveyed || $soil->poorly_share === null) {
            return [];
        }

        $town = trim((string) $soil->name) !== '' ? trim((string) $soil->name) : 'this town';
        $share = (float) $soil->poorly_share;

        if ($share >= self::WET) {
            return [sprintf(
                'About %d%% of the ground in %s is mapped somewhat poorly to poorly drained (USDA soil survey) — soil that holds water rather than shedding it.',
                (int) round($share * 100),
                $town,
            )];
        }

        if ($share <= self::DRY) {
            $dominant = trim((string) $soil->dominant);

            return [$dominant !== ''
                ? sprintf('The ground across most of %s drains freely — the dominant soil is mapped %s (USDA soil survey).', $town, mb_strtolower($dominant))
                : sprintf('Only %d%% of the ground in %s is mapped as draining poorly (USDA soil survey).', (int) round($share * 100), $town)];
        }

        return [];
    }

    /** The stored row for a page's Census GEOID, or null when the town has never been fetched. */
    public function row(?string $geoId): ?TownSoilDrainage
    {
        $geoId = trim((string) $geoId);

        return $geoId === '' ? null : TownSoilDrainage::query()->where('geo_id', $geoId)->first();
    }
}
