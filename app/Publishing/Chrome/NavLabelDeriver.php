<?php

namespace App\Publishing\Chrome;

use App\Models\Content;
use Illuminate\Support\Str;

/**
 * Derives the short header {@see Content::$nav_label} for a child page under its hub: strip the
 * hub's terms from the child's title so a spoke reads short under a heading that already carries the category
 * ("Sump Pumps" › "Sump Pump Installation" → "Installation"). Pure — titles + brand terms in, labels out; the
 * seeder persists them and the operator can override.
 *
 * Rules (from the grouped-nav spec):
 *  - Strip the hub's significant tokens from the child, SINGULAR/PLURAL aware ("Pumps" strips "Pump").
 *  - NEVER strip brand terms — a distinctive brand word (a brand token NOT shared with the hub, so category
 *    words like "sump"/"pump" that a brand happens to contain are still strippable) is kept.
 *  - FALL BACK to the full title (return null) when the result is empty, under ~3 chars, or nothing was
 *    stripped (no shortening to offer).
 *  - COLLISION: if two siblings reduce to the same label, BOTH fall back — a duplicate label is worse than a
 *    long one, and falling back only the second would still leave one wrong.
 */
final class NavLabelDeriver
{
    /** Below this the stripped result is too terse to be a useful label — fall back to the title. */
    private const MIN_LENGTH = 3;

    /**
     * Derive labels for a whole sibling set under one hub, applying the both-siblings collision fallback.
     * Returns the derived label per child, or null where the child should fall back to its full title.
     *
     * @param  array<array-key, string>  $childTitles  child key => title
     * @param  list<string>  $brandTerms  the site's brand words (e.g. tokens of the brand name)
     * @return array<array-key, string|null> child key => derived label, or null to use the title
     */
    public function deriveGroup(array $childTitles, string $hubTitle, array $brandTerms = []): array
    {
        $derived = [];
        foreach ($childTitles as $key => $title) {
            $derived[$key] = $this->derive($title, $hubTitle, $brandTerms);
        }

        // Collision: any label two+ children share (case-insensitive) is dropped for ALL of them.
        $counts = [];
        foreach ($derived as $label) {
            if ($label !== null) {
                $counts[mb_strtolower($label)] = ($counts[mb_strtolower($label)] ?? 0) + 1;
            }
        }
        foreach ($derived as $key => $label) {
            if ($label !== null && ($counts[mb_strtolower($label)] ?? 0) > 1) {
                $derived[$key] = null;
            }
        }

        return $derived;
    }

    /**
     * Derive one child's label under its hub, or null to fall back to the full title. Does not apply
     * collision handling — that needs the sibling set ({@see deriveGroup}).
     *
     * @param  list<string>  $brandTerms
     */
    public function derive(string $childTitle, string $hubTitle, array $brandTerms = []): ?string
    {
        $childTokens = $this->tokenize($childTitle);
        if ($childTokens === []) {
            return null;
        }

        $hubKeys = $this->keySet($this->tokenize($hubTitle));
        // Brand words to protect = brand tokens NOT shared with the hub. A category word the brand happens to
        // contain (brand "Sump Pump Gurus", hub "Sump Pumps") stays strippable; a real brand word ("Gurus")
        // is protected.
        $brandKeys = array_diff_key($this->keySet($this->tokenize(implode(' ', $brandTerms))), $hubKeys);

        $kept = [];
        foreach ($childTokens as $token) {
            $key = $this->key($token);
            $stripThis = isset($hubKeys[$key]) && ! isset($brandKeys[$key]);
            if (! $stripThis) {
                $kept[] = $token;
            }
        }

        // Nothing stripped → no shortening to offer; use the title.
        if (count($kept) === count($childTokens)) {
            return null;
        }

        $label = trim(implode(' ', $kept));
        if (mb_strlen($label) < self::MIN_LENGTH) {
            return null;
        }

        return $label;
    }

    /**
     * Split a title into display tokens (original casing preserved), dropping pure punctuation like "&".
     *
     * @return list<string>
     */
    private function tokenize(string $title): array
    {
        $tokens = preg_split('/\s+/', trim($title)) ?: [];

        return array_values(array_filter($tokens, fn (string $t): bool => $this->key($t) !== ''));
    }

    /**
     * A token's match key: lowercased, punctuation stripped, singularized — so "Pumps" and "pump" match and
     * an "&"/"—" collapses to empty (and is dropped).
     */
    private function key(string $token): string
    {
        $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $token) ?? '');

        return $normalized === '' ? '' : Str::singular($normalized);
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, true> match-key set
     */
    private function keySet(array $tokens): array
    {
        $set = [];
        foreach ($tokens as $token) {
            $key = $this->key($token);
            if ($key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }
}
