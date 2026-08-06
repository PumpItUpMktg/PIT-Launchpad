<?php

namespace App\Support;

/**
 * Adds the tenant's service-area REGION to a service/hub page's SEO title — "Basement Waterproofing" →
 * "Basement Waterproofing in New Jersey & Eastern Pennsylvania" — so the highest-intent commercial pages
 * carry a geo signal. Region is a STATE/REGION-level qualifier only (never a city): service pages stay
 * geo-neutral in silos/content by design (§4); a region on the <title> is the one sanctioned departure.
 *
 * Graceful degrade (fork A): try the richest region that still fits the SERP cap — the full configured
 * phrase, else the short abbreviation form ("NJ & PA"), else the primary state, else leave the title
 * clean rather than truncate a region mid-word. Idempotent: a title that already names the region or any
 * of the tenant's states (a drafter that wrote geo) is returned unchanged, so a repush never double-tags.
 *
 * Nothing here is hardcoded to a tenant: the full region is the tenant's configured `service_area`, and
 * the states are the tenant's own coverage/corporate states.
 *
 * The configured `service_area` is often a "brand tagline · region" slot (e.g. "Sump Pump & Basement
 * Water Specialists · New Jersey & Eastern Pennsylvania") that also renders on the home page. Only the
 * REGION half belongs in a title, so {@see cleanRegion()} peels the trailing segment off a middot/pipe/
 * dash-separated slot (and decodes HTML entities) — the home tagline stays intact, the title gets the
 * clean prose region instead of falling back to bare state abbreviations.
 */
final class ServiceAreaTitle
{
    /**
     * @param  string  $fullRegion  the tenant's configured region phrase, or a "tagline · region" slot
     *                              (only the region half is used, e.g. "New Jersey & Eastern Pennsylvania")
     * @param  list<string>  $stateAbbrevs  the tenant's states, 2-letter (e.g. ["NJ", "PA"])
     */
    public static function qualify(string $title, string $fullRegion, array $stateAbbrevs, int $max = SeoTitle::MAX_LENGTH): string
    {
        $title = trim($title);
        if ($title === '') {
            return $title;
        }

        $abbrevs = array_values(array_unique(array_filter(
            array_map(fn ($s): string => strtoupper(trim((string) $s)), $stateAbbrevs),
            fn (string $s): bool => $s !== '',
        )));
        $full = self::cleanRegion($fullRegion);

        // Already carries geo? leave it (idempotent across repushes; respects a drafter that wrote geo).
        if (self::hasGeo($title, $abbrevs, $full)) {
            return $title;
        }

        // Region forms, richest first; use the longest that keeps the title within the cap.
        $forms = array_values(array_unique(array_filter([
            $full,
            implode(' & ', $abbrevs),
            $abbrevs[0] ?? '',
        ], fn (string $f): bool => trim($f) !== '')));

        foreach ($forms as $form) {
            $candidate = $title.' in '.$form;
            if (mb_strlen($candidate) <= $max) {
                return $candidate;
            }
        }

        return $title; // nothing fits — a clean title beats a truncated region
    }

    /**
     * Peel the REGION out of a possibly-tagline slot. A "brand tagline · region" slot (middot / pipe /
     * en–em dash separated) carries the geo half LAST by convention — take the trailing segment; a slot
     * with no such separator is already a clean region and is returned whole. HTML entities are decoded
     * ("&amp;" → "&"). The ASCII hyphen is intentionally NOT a separator, so hyphenated place names
     * ("Wilkes-Barre") survive.
     */
    private static function cleanRegion(string $raw): string
    {
        $raw = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5)));
        if ($raw === '') {
            return '';
        }

        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\s*[·|–—]\s*/u', $raw) ?: [$raw]),
            fn (string $p): bool => $p !== '',
        ));

        return $parts === [] ? $raw : (string) end($parts);
    }

    /** True when the title already carries geo — a tenant state (abbrev/name) OR any "in {Place}" clause. */
    private static function hasGeo(string $title, array $abbrevs, string $full): bool
    {
        // An existing "in {Capitalized}" clause (a city, region, anything) — never stack a second "in".
        // Skipping here is the SAFE outcome: a clean title rather than "… in Austin in NJ".
        if (preg_match('/\bin\s+\p{Lu}/u', $title) === 1) {
            return true;
        }

        $lower = mb_strtolower($title);

        foreach ($abbrevs as $a) {
            if ($a !== '' && preg_match('/\b'.preg_quote(mb_strtolower($a), '/').'\b/u', $lower) === 1) {
                return true;
            }
        }
        foreach (self::significantWords($full) as $w) {
            if (str_contains($lower, mb_strtolower($w))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The state-name words in the configured region (≥4 letters, minus directional/filler words) — so a
     * title already containing "New Jersey" or "Pennsylvania" is recognized as geo without a state table.
     *
     * @return list<string>
     */
    private static function significantWords(string $region): array
    {
        $stop = ['and', 'the', 'new', 'eastern', 'western', 'northern', 'southern', 'central', 'greater', 'area', 'areas', 'county', 'counties', 'region', 'metro'];
        $out = [];
        foreach (preg_split('/[^\p{L}]+/u', $region) ?: [] as $w) {
            $w = trim((string) $w);
            if (mb_strlen($w) >= 4 && ! in_array(mb_strtolower($w), $stop, true)) {
                $out[] = $w;
            }
        }

        return $out;
    }
}
