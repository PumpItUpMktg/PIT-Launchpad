<?php

namespace App\Citations;

/**
 * Attribution-before-judging (§ Citations, Fix 1 — the module's hardest correctness requirement).
 *
 * Before a found listing can be judged (correct / needs-fix / duplicate), the scan must decide WHICH of a
 * tenant's locations it belongs to. Getting this wrong is destructive: "fixing" a sibling's correct listing
 * to a different location's NAP breaks a live citation. So a result is scored against EVERY sibling and only
 * a clear winner is attributed; anything weak or tied is `ambiguous` → operator review, never a guess.
 *
 * Signal weights (a local phone is near-deterministic; a shared/corporate number is near-worthless):
 *   - found phone == a location's OWN local phone_primary        → +60  (decisive)
 *   - street number + street match                                → +40  (strong)
 *   - a shared number OWNED by a location, AND the address agrees  → +25  (medium, address-gated)
 *   - city match                                                  → +8   (weak)
 *   - postal match                                                → +7   (weak)
 *   - a shared number with NO owner                               → +0   (zero signal — never attributes)
 */
final class CitationAttributor
{
    private const DECISIVE = 60;

    private const STREET = 40;

    private const SHARED_OWNED = 25;

    private const CITY = 8;

    private const POSTAL = 7;

    /** Below this the best candidate is too weak to trust. */
    private const CONFIDENCE_FLOOR = 30;

    /** Two candidates within this band are a tie. */
    private const TIE_BAND = 15;

    public function __construct(private readonly NapNormalizer $nap = new NapNormalizer) {}

    /**
     * @param  array{name?: ?string, address?: ?string, phone?: ?string}  $found  the found listing (as scraped)
     * @param  list<array{location_id: string, phone_primary?: ?string, address_1?: ?string, city?: ?string, postal?: ?string}>  $siblings  all of the tenant's locations
     * @param  array<string, ?string>  $sharedPhones  normalized shared number => owning location_id (or null for an un-owned shared line)
     */
    public function attribute(array $found, array $siblings, array $sharedPhones = []): AttributionResult
    {
        if ($siblings === []) {
            return AttributionResult::unresolved();
        }

        $foundPhone = $this->nap->phone((string) ($found['phone'] ?? ''));
        $foundAddr = (string) ($found['address'] ?? '');
        $foundStreetNo = $this->nap->streetNumber($this->nap->address($foundAddr));
        $foundAddrNorm = $this->nap->address($foundAddr);

        $scored = [];
        foreach ($siblings as $sib) {
            $score = 0;

            $streetMatch = $foundStreetNo !== ''
                && $foundStreetNo === $this->nap->streetNumber($this->nap->address((string) ($sib['address_1'] ?? '')))
                && $this->streetNameAgrees($foundAddrNorm, (string) ($sib['address_1'] ?? ''));

            if ($foundPhone !== '') {
                $ownPhone = $this->nap->phone((string) ($sib['phone_primary'] ?? ''));
                if ($ownPhone !== '' && $foundPhone === $ownPhone) {
                    $score += self::DECISIVE;
                } elseif (array_key_exists($foundPhone, $sharedPhones)) {
                    // A shared/corporate number. It only helps a location that OWNS it, and only when the
                    // address corroborates — otherwise a shared line would smear across every sibling.
                    if (($sharedPhones[$foundPhone] ?? null) === $sib['location_id'] && $streetMatch) {
                        $score += self::SHARED_OWNED;
                    }
                }
            }

            if ($streetMatch) {
                $score += self::STREET;
            }
            if ($this->contains($foundAddrNorm, (string) ($sib['city'] ?? ''))) {
                $score += self::CITY;
            }
            if ($this->contains($foundAddrNorm, (string) ($sib['postal'] ?? ''))) {
                $score += self::POSTAL;
            }

            $scored[] = ['location_id' => $sib['location_id'], 'score' => $score];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $scored[0];
        $second = $scored[1]['score'] ?? 0;

        if ($best['score'] < self::CONFIDENCE_FLOOR) {
            return AttributionResult::unresolved();
        }
        if (count($scored) > 1 && ($best['score'] - $second) < self::TIE_BAND) {
            // A real tie between siblings — the destructive case. Never guess.
            return new AttributionResult($best['location_id'], min(100, $best['score']), true);
        }

        return new AttributionResult($best['location_id'], min(100, $best['score']), false);
    }

    /** True when `$needle` (normalized) is a non-empty token-substring of `$haystack`. */
    private function contains(string $haystack, string $needle): bool
    {
        $n = $this->nap->name($needle);

        return $n !== '' && str_contains($haystack, $n);
    }

    /** A shared street number is not enough — require at least one street-name token in common. */
    private function streetNameAgrees(string $foundAddrNorm, string $siblingAddr1): bool
    {
        $sibNorm = $this->nap->address($siblingAddr1);
        $sibTokens = array_filter(
            explode(' ', $sibNorm),
            fn (string $t): bool => $t !== '' && ! ctype_digit($t),
        );
        foreach ($sibTokens as $t) {
            if (str_contains($foundAddrNorm, $t)) {
                return true;
            }
        }

        return false;
    }
}
