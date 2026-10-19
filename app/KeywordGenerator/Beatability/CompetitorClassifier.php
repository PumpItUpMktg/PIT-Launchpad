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
        'arsrescuerooter', 'americanresidential', 'mrhandyman', 'servicemaster', 'neighborly',
        'servpro', 'basementsystems', 'jackery', 'zoomdrain',
    ];

    private const EDITORIAL = [
        'wikipedia', 'forbes', 'nytimes', 'thisoldhouse', 'familyhandyman',
        'bobvila', 'hgtv', 'consumerreports', 'doityourself', 'thespruce', 'fixr', 'homeguide',
        'reddit', 'quora', 'youtube',
    ];

    /**
     * The registrable part of a host — the last two labels, or three under a two-level public suffix
     * (co.uk, com.au): "m.yelp.com" → "yelp.com", "ca.jackery.com" → "jackery.com".
     */
    public static function registrable(string $host): string
    {
        $labels = array_values(array_filter(explode('.', strtolower(trim($host)))));
        $n = count($labels);
        if ($n <= 2) {
            return implode('.', $labels);
        }
        $second = $labels[$n - 2];
        $tld = $labels[$n - 1];
        // co.uk / com.au style, and the two-letter resellers under a generic TLD (us.com, uk.com, eu.com).
        $take = (strlen($tld) === 2 && in_array($second, ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac'], true))
            || (strlen($second) === 2 && in_array($tld, ['com', 'net', 'org'], true))
            ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    public function classify(string $domain): CompetitorClass
    {
        $domain = strtolower(preg_replace('/^www\./', '', trim($domain)) ?? $domain);
        // The registrable name, not the first label: m.yelp.com is Yelp, ca.jackery.com is Jackery.
        $stem = explode('.', self::registrable($domain))[0];

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
