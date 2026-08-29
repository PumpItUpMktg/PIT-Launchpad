<?php

namespace App\Citations;

/**
 * Normalizes NAP fields so the scan flags only SUBSTANTIVE mismatches, never formatting noise (§ Citations).
 * "Suite" vs "Ste", "Road" vs "Rd", phone punctuation, case, trailing punctuation all normalize to the same
 * token; a different phone number, a different street number, or a different business name survive as real
 * findings. An over-strict matcher floods the fix list with noise and destroys VA trust — so normalize hard.
 */
final class NapNormalizer
{
    /** Street-suffix + unit abbreviations collapsed to one canonical token. */
    private const ABBREV = [
        'street' => 'st', 'road' => 'rd', 'avenue' => 'ave', 'av' => 'ave', 'boulevard' => 'blvd',
        'drive' => 'dr', 'lane' => 'ln', 'court' => 'ct', 'place' => 'pl', 'terrace' => 'ter',
        'highway' => 'hwy', 'parkway' => 'pkwy', 'circle' => 'cir', 'square' => 'sq',
        'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w',
        'suite' => 'ste', 'apartment' => 'apt', 'apt' => 'apt', 'unit' => 'unit', 'building' => 'bldg', 'floor' => 'fl',
    ];

    /** Digits only — "(973) 786-7834", "+1 973 786 7834" and "973.786.7834" all normalize equal. */
    public function phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        // Drop a leading US country code so 1-973… equals 973….
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    /** Lowercased, punctuation stripped, suffix/unit abbreviations collapsed, "#" → "ste", whitespace collapsed. */
    public function address(string $value): string
    {
        $s = mb_strtolower(trim($value));
        $s = str_replace('#', ' ste ', $s);
        $s = preg_replace('/[.,]/', ' ', $s) ?? $s;
        $tokens = array_values(array_filter(preg_split('/\s+/', $s) ?: []));
        $tokens = array_map(fn (string $t): string => self::ABBREV[$t] ?? $t, $tokens);

        return trim(implode(' ', $tokens));
    }

    /** Lowercased, punctuation stripped, whitespace collapsed. */
    public function name(string $value): string
    {
        $s = mb_strtolower(trim($value));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** The leading house number of an address ("123 Main St" → "123"), or '' when there isn't one. */
    public function streetNumber(string $address): string
    {
        return preg_match('/^\s*(\d+)/', $address, $m) === 1 ? $m[1] : '';
    }

    /**
     * The substantive field mismatches between a found listing and the canonical NAP. Empty = a clean match
     * (formatting-only differences are not findings). Phone comparison is left to the caller (shared-number
     * rules, Fix 7); this covers name + address (incl. the street-number check).
     *
     * @param  array{name?: ?string, address?: ?string}  $found
     * @param  array{business_name: string, address_1: string, address_2?: ?string}  $canonical
     * @return array<string, array{found: string, expected: string}>
     */
    public function mismatches(array $found, array $canonical): array
    {
        $out = [];

        $foundName = (string) ($found['name'] ?? '');
        if ($foundName !== '' && $this->name($foundName) !== $this->name($canonical['business_name'])) {
            $out['name'] = ['found' => $foundName, 'expected' => $canonical['business_name']];
        }

        $foundAddr = (string) ($found['address'] ?? '');
        $expectedAddr = trim($canonical['address_1'].' '.($canonical['address_2'] ?? ''));
        if ($foundAddr !== '' && $this->address($foundAddr) !== $this->address($expectedAddr)) {
            $out['address'] = ['found' => $foundAddr, 'expected' => $expectedAddr];
        }

        return $out;
    }
}
