<?php

namespace App\Local\Grounding;

use App\Models\TownFloodZone;

/**
 * A town's FEMA flood mapping, said in sentences a drafter can use without over-claiming.
 *
 * The line this holds: FEMA maps LAND, and a page may say what FEMA mapped. It may not say anything about
 * a reader's own house — whether it floods, whether it needs insurance, whether it sits in the zone. The
 * town contains a floodplain; the visitor may or may not be in it, and only their own address answers
 * that. So the facts name the zones and say plainly that the parts matter, and the drafter's standing
 * "never invent local detail" rule does the rest.
 *
 * No share of the town is ever stated: intersecting NFHL polygons are not clipped to the town boundary, so
 * a count is provenance, not area, and a percentage from it would be invented.
 */
final class TownFloodFacts
{
    /** What the common Special Flood Hazard Area codes mean, in the words FEMA itself uses. */
    private const MEANING = [
        'A' => 'the 1%-annual-chance floodplain, mapped without a base flood elevation',
        'AE' => 'the 1%-annual-chance floodplain, with base flood elevations published',
        'AH' => 'shallow 1%-annual-chance flooding, typically ponding',
        'AO' => 'shallow 1%-annual-chance flooding, typically sheet flow',
        'AR' => 'a floodplain temporarily protected by a levee under restoration',
        'A99' => 'a floodplain to be protected by a flood-control system under construction',
        'V' => 'coastal floodplain with wave action',
        'VE' => 'coastal floodplain with wave action, with base flood elevations published',
    ];

    /**
     * @return list<string>
     */
    public function for(?TownFloodZone $flood): array
    {
        if ($flood === null || ! $flood->mapped) {
            return [];   // never mapped is not the same as no hazard, and neither is a fact worth writing
        }

        $town = trim((string) $flood->name) !== '' ? trim((string) $flood->name) : 'this town';
        $sfha = $flood->sfhaZones();

        if ($sfha === []) {
            return [sprintf(
                'FEMA maps no Special Flood Hazard Area within %s — it lies outside the 1%%-annual-chance floodplain.',
                $town,
            )];
        }

        $facts = [sprintf(
            'FEMA maps Special Flood Hazard Areas in parts of %s (zone%s %s) — the regulatory floodplain.',
            $town,
            count($sfha) === 1 ? '' : 's',
            $this->list($sfha),
        )];

        // One plain-English gloss, for the zone FEMA publishes elevations for where there is a choice.
        $primary = in_array('AE', $sfha, true) ? 'AE' : $sfha[0];
        if (isset(self::MEANING[$primary])) {
            $facts[] = sprintf('Zone %s is %s.', $primary, self::MEANING[$primary]);
        }

        // Said once, plainly: the town contains a floodplain; a given address may sit well outside it.
        $facts[] = sprintf('Whether any one address in %s sits inside that zone depends on the address, not the town.', $town);

        return $facts;
    }

    /** The stored row for a page's Census GEOID, or null when the town has never been fetched. */
    public function row(?string $geoId): ?TownFloodZone
    {
        $geoId = trim((string) $geoId);

        return $geoId === '' ? null : TownFloodZone::query()->where('geo_id', $geoId)->first();
    }

    /**
     * @param  list<string>  $zones
     */
    private function list(array $zones): string
    {
        if (count($zones) === 1) {
            return $zones[0];
        }
        $last = array_pop($zones);

        return implode(', ', $zones).' and '.$last;
    }
}
