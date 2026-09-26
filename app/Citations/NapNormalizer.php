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
     * (formatting-only differences are not findings).
     *
     * A directory usually publishes the FULL address ("123 Main St, Clifton, NJ 07013") while the canonical
     * street line is just "123 Main St", so the address check asks whether the canonical street line appears
     * inside the found address (after normalization) and, when both carry a ZIP, that the ZIPs agree. A
     * canonical NAP with no street (a service-area business) skips the address check. Phone is a mismatch
     * only when the found number is neither the location's own line nor one of the tenant's shared numbers.
     *
     * @param  array{name?: ?string, address?: ?string, phone?: ?string}  $found
     * @param  array{business_name: string, address_1?: ?string, address_2?: ?string, postal?: ?string, phone?: ?string}  $canonical
     * @param  list<string>  $sharedPhones  raw or normalized shared/corporate numbers that are never a mismatch
     * @return array<string, array{found: string, expected: string}>
     */
    public function mismatches(array $found, array $canonical, array $sharedPhones = []): array
    {
        $out = [];

        $foundName = (string) ($found['name'] ?? '');
        if ($foundName !== '' && $this->name($foundName) !== $this->name($canonical['business_name'])) {
            $out['name'] = ['found' => $foundName, 'expected' => $canonical['business_name']];
        }

        $foundAddr = (string) ($found['address'] ?? '');
        $street = trim((string) ($canonical['address_1'] ?? ''));
        // A found address with no street number (a directory that publishes only "Clifton, NJ") has no
        // street to fault — only the ZIP, when present, can disagree.
        if ($foundAddr !== '' && $street !== '' && ($this->streetNumber($this->address($foundAddr)) !== '' || preg_match('/\b\d{5}\b/', $foundAddr) === 1)) {
            $expectedAddr = trim($street.' '.((string) ($canonical['address_2'] ?? '')));
            $foundNorm = $this->address($foundAddr);
            $streetNorm = $this->address($street);
            $streetKnown = $this->streetNumber($foundNorm) !== '';
            $zipMismatch = false;
            $postal = preg_match('/\b(\d{5})\b/', (string) ($canonical['postal'] ?? ''), $pm) === 1 ? $pm[1] : '';
            if ($postal !== '' && preg_match_all('/\b(\d{5})\b/', $foundAddr, $fm) > 0) {
                $zipMismatch = ! in_array($postal, $fm[1], true);
            }
            if (($streetKnown && ! str_contains(' '.$foundNorm.' ', ' '.$streetNorm.' ')) || $zipMismatch) {
                $out['address'] = ['found' => $foundAddr, 'expected' => trim($expectedAddr.' '.((string) ($canonical['postal'] ?? '')))];
            }
        }

        $foundPhone = $this->phone((string) ($found['phone'] ?? ''));
        $expectedPhone = $this->phone((string) ($canonical['phone'] ?? ''));
        if ($foundPhone !== '' && $expectedPhone !== '' && $foundPhone !== $expectedPhone) {
            $shared = array_map(fn (string $p): string => $this->phone($p), $sharedPhones);
            if (! in_array($foundPhone, $shared, true)) {
                $out['phone'] = ['found' => (string) $found['phone'], 'expected' => (string) $canonical['phone']];
            }
        }

        return $out;
    }
}
