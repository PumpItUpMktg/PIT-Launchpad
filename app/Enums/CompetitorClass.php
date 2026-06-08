<?php

namespace App\Enums;

/**
 * Classification of a SERP/local-pack competitor, so we know whether a winnable
 * lane exists beneath the big players.
 */
enum CompetitorClass: string
{
    case NationalBigBox = 'national_big_box';
    case AggregatorDirectory = 'aggregator_directory';
    case LocalCompetitor = 'local_competitor';
    case EditorialGov = 'editorial_gov';

    /**
     * Whether a local business can realistically displace this competitor in
     * the local-pack lane (nationals and aggregators rarely hold the pack).
     */
    public function beatableInLocalPack(): bool
    {
        return $this === self::LocalCompetitor;
    }
}
