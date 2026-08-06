<?php

namespace App\Publishing\Seo;

use App\Models\Content;

/**
 * Audits whether a commercial page's TARGET KEYWORD is actually used on the page — the on-page half of
 * ranking for it. For each page it checks the five signals that matter for SEO and reports where the
 * keyword lands (exact phrase / partial token match / absent):
 *
 *   - slug              — the URL path
 *   - title             — the SEO title (meta.seo.title, falling back to the page title)
 *   - h1                — the hero headline slot (the on-page H1)
 *   - meta_description  — the SERP snippet
 *   - body              — the drafted copy (all slot text)
 *
 * From those it derives a verdict: `optimized` (exact in title + H1, present in slug), `partial`
 * (keyword present but not tight in the critical spots), `off_target` (missing from title or H1 — the
 * page isn't really about its target), or `no_target` (no target keyword set at all). Read-only.
 */
class KeywordUsageAuditor
{
    public const EXACT = 'exact';

    public const PARTIAL = 'partial';

    public const ABSENT = 'absent';

    /**
     * @return array{
     *   id: string, title: string, slug: string, page_type: ?string, keyword: ?string,
     *   placements: array<string, string>, missing: list<string>, verdict: string
     * }
     */
    public function analyze(Content $page): array
    {
        $keyword = $page->targetKeyword?->query;
        $slots = is_array($page->slot_payload) ? $page->slot_payload : [];
        $meta = is_array($page->meta) ? $page->meta : [];
        $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];

        $base = [
            'id' => (string) $page->id,
            'title' => (string) $page->title,
            'slug' => (string) $page->slug,
            'page_type' => $page->page_type?->value,
        ];

        if (! is_string($keyword) || trim($keyword) === '') {
            return $base + ['keyword' => null, 'placements' => [], 'missing' => [], 'verdict' => 'no_target'];
        }

        $fields = [
            'slug' => str_replace(['-', '/'], ' ', (string) $page->slug),
            'title' => (string) ($seo['title'] ?? $page->title ?? ''),
            'h1' => $this->leafText($slots['hero_headline'] ?? ''),
            'meta_description' => (string) ($seo['meta_description'] ?? ''),
            'body' => $this->allText($slots),
        ];

        $placements = [];
        foreach ($fields as $key => $text) {
            $placements[$key] = self::placement($keyword, $text);
        }

        $missing = array_keys(array_filter($placements, fn (string $p): bool => $p === self::ABSENT));

        $verdict = self::verdict($placements);
        // Over-optimization: an on-target page whose <title> is ONLY the keyword (an exact-match, nothing
        // else) — widely penalized by search engines. A distinct finding, not a win.
        if ($verdict === 'optimized' && self::isBareKeyword($keyword, $fields['title'])) {
            $verdict = 'over_optimized';
        }

        return $base + [
            'keyword' => $keyword,
            'placements' => $placements,
            'missing' => $missing,
            'verdict' => $verdict,
        ];
    }

    /** True when the text is NOTHING BUT the keyword (normalized) — the exact-match over-optimization case. */
    public static function isBareKeyword(string $keyword, string $text): bool
    {
        $needle = self::normalize($keyword);

        return $needle !== '' && self::normalize($text) === $needle;
    }

    /**
     * Where does the keyword land in this text: the exact (normalized) phrase, all of its tokens
     * present (any order), or absent.
     */
    public static function placement(string $keyword, string $haystack): string
    {
        $needle = self::normalize($keyword);
        $hay = self::normalize($haystack);

        if ($needle === '') {
            return self::ABSENT;
        }
        if ($hay !== '' && str_contains($hay, $needle)) {
            return self::EXACT;
        }

        $hayTokens = array_flip(explode(' ', $hay));
        foreach (explode(' ', $needle) as $token) {
            if (! isset($hayTokens[$token])) {
                return self::ABSENT;
            }
        }

        return self::PARTIAL;
    }

    /**
     * @param  array<string, string>  $placements
     */
    public static function verdict(array $placements): string
    {
        $title = $placements['title'] ?? self::ABSENT;
        $h1 = $placements['h1'] ?? self::ABSENT;
        $slug = $placements['slug'] ?? self::ABSENT;

        // Missing from either of the two spots search engines weight most = the page isn't on-target.
        if ($title === self::ABSENT || $h1 === self::ABSENT) {
            return 'off_target';
        }

        // Tight everywhere it counts.
        if ($title === self::EXACT && $h1 === self::EXACT && $slug !== self::ABSENT) {
            return 'optimized';
        }

        return 'partial';
    }

    /** lowercase, punctuation → spaces, collapse whitespace — so "Sump-Pump Installation!" ≈ "sump pump installation". */
    private static function normalize(string $s): string
    {
        $s = strtolower($s);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** A single slot value flattened to its string (a leaf string, or the joined strings of a structure). */
    private function leafText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return $this->allText($value);
        }

        return '';
    }

    /**
     * All string leaves under a slot payload joined — the page's full drafted text, for the body check.
     *
     * @param  array<array-key, mixed>  $slots
     */
    private function allText(array $slots): string
    {
        $parts = [];
        array_walk_recursive($slots, function (mixed $leaf) use (&$parts): void {
            if (is_string($leaf)) {
                $parts[] = $leaf;
            }
        });

        return implode(' ', $parts);
    }
}
