<?php

namespace App\KeywordGenerator\Beatability;

use App\Enums\CompetitorClass;

/**
 * Classifies a competitor domain so we know whether a winnable lane exists
 * beneath the big players.
 */
class CompetitorClassifier
{
    private const AGGREGATORS = [
        'yelp', 'angi', 'angieslist', 'homeadvisor', 'thumbtack', 'bbb',
        'yellowpages', 'mapquest', 'nextdoor', 'houzz', 'porch', 'networx',
        'expertise', 'threebestrated',
    ];

    private const NATIONAL = [
        'homedepot', 'lowes', 'amazon', 'walmart', 'target', 'sears',
        'rotorooter', 'mrrooter', 'rooterman', 'benjaminfranklinplumbing',
        'arsrescuerooter', 'americanresidential',
    ];

    private const EDITORIAL = [
        'wikipedia', 'forbes', 'nytimes', 'thisoldhouse', 'familyhandyman',
        'bobvila', 'hgtv', 'consumerreports',
    ];

    public function classify(string $domain): CompetitorClass
    {
        $domain = strtolower(preg_replace('/^www\./', '', trim($domain)) ?? $domain);
        $stem = explode('.', $domain)[0];

        if (str_ends_with($domain, '.gov') || str_ends_with($domain, '.edu') || in_array($stem, self::EDITORIAL, true)) {
            return CompetitorClass::EditorialGov;
        }

        if (in_array($stem, self::AGGREGATORS, true)) {
            return CompetitorClass::AggregatorDirectory;
        }

        if (in_array($stem, self::NATIONAL, true)) {
            return CompetitorClass::NationalBigBox;
        }

        return CompetitorClass::LocalCompetitor;
    }
}
