<?php

namespace App\SiloCreator;

use App\Models\Market;
use App\Models\Scopes\SiteScope;

/**
 * Hard rule: silos and rule_sets carry no geo. Geographic relevance lives only
 * on location pages. This flags any silo name or rule_set containing geo/city
 * terms — derived from the site's own Markets plus a generic geo lexicon.
 */
class GeoNeutralValidator
{
    private const GENERIC = ['near me', 'nearby', 'in town', 'local area'];

    private const STATES = [
        'alabama', 'alaska', 'arizona', 'arkansas', 'california', 'colorado',
        'connecticut', 'delaware', 'florida', 'georgia', 'hawaii', 'idaho',
        'illinois', 'indiana', 'iowa', 'kansas', 'kentucky', 'louisiana',
        'maine', 'maryland', 'massachusetts', 'michigan', 'minnesota',
        'mississippi', 'missouri', 'montana', 'nebraska', 'nevada', 'ohio',
        'oklahoma', 'oregon', 'pennsylvania', 'tennessee', 'texas', 'utah',
        'vermont', 'virginia', 'washington', 'wisconsin', 'wyoming',
    ];

    /**
     * @return list<string>
     */
    public function violations(string $name, RuleSet $ruleSet, string $siteId): array
    {
        $haystack = ' '.mb_strtolower($name.' '.$ruleSet->allTerms()).' ';
        $terms = [...$this->marketTerms($siteId), ...self::GENERIC, ...self::STATES];

        $found = [];
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            if (preg_match('/\b'.preg_quote($term, '/').'\b/', $haystack) === 1) {
                $found[$term] = true;
            }
        }

        return array_keys($found);
    }

    public function isGeoNeutral(string $name, RuleSet $ruleSet, string $siteId): bool
    {
        return $this->violations($name, $ruleSet, $siteId) === [];
    }

    /**
     * @return list<string>
     */
    private function marketTerms(string $siteId): array
    {
        $terms = [];

        $markets = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get(['name', 'region', 'neighborhoods']);

        foreach ($markets as $market) {
            $name = (string) $market->name;
            if ($name !== '') {
                $terms[mb_strtolower($name)] = true;
            }
            $region = (string) $market->region;
            if ($region !== '') {
                $terms[mb_strtolower($region)] = true;
            }
            foreach ((array) $market->neighborhoods as $neighborhood) {
                $neighborhood = (string) $neighborhood;
                if ($neighborhood !== '') {
                    $terms[mb_strtolower($neighborhood)] = true;
                }
            }
        }

        return array_keys($terms);
    }
}
