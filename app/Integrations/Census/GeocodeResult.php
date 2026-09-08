<?php

namespace App\Integrations\Census;

/**
 * A resolved geocode: the point, the address the geocoder matched (so the operator can confirm it
 * resolved to the right place), and — when the geocoder reports them — Google's two precision signals.
 *
 * `locationType` / `partialMatch` follow the "absent is never negative" rule (spec 5, rule 7): NULL means
 * the geocoder does not report the signal at all (the keyless Census geocoder, the mock) — "not assessed",
 * NOT "clean". Google always reports both, so a Google result carries a concrete value: `partialMatch`
 * `false` is an assessed full match, distinct from a Census `null`. Google exposes no numeric confidence
 * score, so there is deliberately none here — these two flags are the whole signal it gives.
 */
final class GeocodeResult
{
    /**
     * @param  string  $matchedAddress  the address the geocoder actually matched
     * @param  ?string  $locationType  Google `geometry.location_type` — ROOFTOP | RANGE_INTERPOLATED |
     *                                 GEOMETRIC_CENTER | APPROXIMATE (descending precision); null when not reported
     * @param  ?bool  $partialMatch  Google `partial_match` — true when Google matched on something other
     *                               than the full address as given (the Trooper/Norristown mis-place class); false when
     *                               it reported a full match; null when the geocoder does not report it
     */
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
        public readonly string $matchedAddress,
        public readonly ?string $locationType = null,
        public readonly ?bool $partialMatch = null,
    ) {}
}
