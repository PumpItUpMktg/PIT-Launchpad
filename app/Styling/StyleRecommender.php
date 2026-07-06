<?php

namespace App\Styling;

/**
 * Deterministic brand/voice → style-variation mapping (the Gutenberg pivot's recommendation brain).
 * Same governance model as auto-arrange: the system decides and applies a pick, the operator
 * confirms or overrides. The mapping is intentionally interpretable (ordered rules, not a black box)
 * so the recommendation screen can explain *why* a style was chosen.
 *
 *   - direct / commercial  → Bold & Direct
 *   - premium / careful    → Clean & Trustworthy  (the trustworthy default)
 *   - local / relational   → Warm & Local
 */
final class StyleRecommender
{
    /** Audience wording that reads as commercial/B2B rather than consumer. */
    private const COMMERCIAL_AUDIENCE = ['commercial', 'business', 'propert', 'manager', 'contractor', 'industrial', 'municipal', 'facilit', 'b2b', 'developer'];

    public function recommend(StyleSignals $signals): StyleVariation
    {
        $audience = mb_strtolower(trim($signals->audience));

        // 1. Commercial audience or a direct, low-warmth tone → Bold. A commercial brand wants the
        //    confident, high-contrast face; a "direct expert" voice does too.
        if ($this->isCommercial($audience) || ($signals->warmth <= 0.5 && $signals->formality >= 0.55)) {
            return StyleVariation::Bold;
        }

        // 2. Genuinely warm/relational tone → Warm (the local, relationship-led face).
        if ($signals->warmth >= 0.75) {
            return StyleVariation::Warm;
        }

        // 3. Everything else → Clean, the careful/premium trustworthy default.
        return StyleVariation::Clean;
    }

    private function isCommercial(string $audience): bool
    {
        if ($audience === '') {
            return false;
        }

        foreach (self::COMMERCIAL_AUDIENCE as $needle) {
            if (str_contains($audience, $needle)) {
                return true;
            }
        }

        return false;
    }
}
