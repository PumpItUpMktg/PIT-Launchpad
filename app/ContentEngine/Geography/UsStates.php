<?php

namespace App\ContentEngine\Geography;

/**
 * The 50 US states (+ DC): abbreviation ↔ name, and land-border adjacency for the region ring. Static
 * reference data — the tenant's footprint is read from its own records, this only names the neighbours.
 */
final class UsStates
{
    /** @var array<string, string> */
    public const NAMES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
        'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
        'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon',
        'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
        'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /** @var array<string, list<string>> land borders (DC counted with MD/VA) */
    public const NEIGHBORS = [
        'AL' => ['FL', 'GA', 'MS', 'TN'], 'AK' => [], 'AZ' => ['CA', 'CO', 'NM', 'NV', 'UT'],
        'AR' => ['LA', 'MO', 'MS', 'OK', 'TN', 'TX'], 'CA' => ['AZ', 'NV', 'OR'],
        'CO' => ['AZ', 'KS', 'NE', 'NM', 'OK', 'UT', 'WY'], 'CT' => ['MA', 'NY', 'RI'],
        'DE' => ['MD', 'NJ', 'PA'], 'DC' => ['MD', 'VA'], 'FL' => ['AL', 'GA'],
        'GA' => ['AL', 'FL', 'NC', 'SC', 'TN'], 'HI' => [], 'ID' => ['MT', 'NV', 'OR', 'UT', 'WA', 'WY'],
        'IL' => ['IA', 'IN', 'KY', 'MO', 'WI'], 'IN' => ['IL', 'KY', 'MI', 'OH'],
        'IA' => ['IL', 'MN', 'MO', 'NE', 'SD', 'WI'], 'KS' => ['CO', 'MO', 'NE', 'OK'],
        'KY' => ['IL', 'IN', 'MO', 'OH', 'TN', 'VA', 'WV'], 'LA' => ['AR', 'MS', 'TX'], 'ME' => ['NH'],
        'MD' => ['DC', 'DE', 'PA', 'VA', 'WV'], 'MA' => ['CT', 'NH', 'NY', 'RI', 'VT'],
        'MI' => ['IN', 'OH', 'WI'], 'MN' => ['IA', 'ND', 'SD', 'WI'], 'MS' => ['AL', 'AR', 'LA', 'TN'],
        'MO' => ['AR', 'IA', 'IL', 'KS', 'KY', 'NE', 'OK', 'TN'], 'MT' => ['ID', 'ND', 'SD', 'WY'],
        'NE' => ['CO', 'IA', 'KS', 'MO', 'SD', 'WY'], 'NV' => ['AZ', 'CA', 'ID', 'OR', 'UT'],
        'NH' => ['MA', 'ME', 'VT'], 'NJ' => ['DE', 'NY', 'PA'], 'NM' => ['AZ', 'CO', 'OK', 'TX', 'UT'],
        'NY' => ['CT', 'MA', 'NJ', 'PA', 'VT'], 'NC' => ['GA', 'SC', 'TN', 'VA'], 'ND' => ['MN', 'MT', 'SD'],
        'OH' => ['IN', 'KY', 'MI', 'PA', 'WV'], 'OK' => ['AR', 'CO', 'KS', 'MO', 'NM', 'TX'],
        'OR' => ['CA', 'ID', 'NV', 'WA'], 'PA' => ['DE', 'MD', 'NJ', 'NY', 'OH', 'WV'], 'RI' => ['CT', 'MA'],
        'SC' => ['GA', 'NC'], 'SD' => ['IA', 'MN', 'MT', 'ND', 'NE', 'WY'],
        'TN' => ['AL', 'AR', 'GA', 'KY', 'MO', 'MS', 'NC', 'VA'], 'TX' => ['AR', 'LA', 'NM', 'OK'],
        'UT' => ['AZ', 'CO', 'ID', 'NM', 'NV', 'WY'], 'VT' => ['MA', 'NH', 'NY'],
        'VA' => ['DC', 'KY', 'MD', 'NC', 'TN', 'WV'], 'WA' => ['ID', 'OR'],
        'WV' => ['KY', 'MD', 'OH', 'PA', 'VA'], 'WI' => ['IA', 'IL', 'MI', 'MN'],
        'WY' => ['CO', 'ID', 'MT', 'NE', 'SD', 'UT'],
    ];

    /** A 2-letter code or a full name → the 2-letter code, or null when it is not a US state. */
    public static function code(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        $upper = strtoupper($v);
        if (isset(self::NAMES[$upper])) {
            return $upper;
        }
        foreach (self::NAMES as $code => $name) {
            if (strcasecmp($name, $v) === 0) {
                return $code;
            }
        }

        return null;
    }

    public static function name(string $code): string
    {
        return self::NAMES[strtoupper($code)] ?? $code;
    }
}
