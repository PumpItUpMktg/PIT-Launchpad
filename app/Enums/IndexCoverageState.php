<?php

namespace App\Enums;

/**
 * The normalized index-coverage verdict for one URL, derived from the Google Search Console URL
 * Inspection API's free-text `coverageState` (+ `verdict`). This is the AUTHORITATIVE "is Google
 * holding this URL" signal — distinct from impressions>0 (a page can be indexed with zero impressions).
 *
 * The mapping is intentionally coarse and honest: we key on the stable phrases Google returns rather
 * than inventing precision it doesn't give. `Indexed` is the only state that counts as truly indexed;
 * `ExcludedRedirect` is the CORRECT state for a 301 source (e.g. `/hoboken/` → `/hoboken-nj`) — the
 * target is what should be indexed, so a redirect URL reading "excluded by redirect" is success, not a
 * defect.
 */
enum IndexCoverageState: string
{
    case Indexed = 'indexed';
    case CrawledNotIndexed = 'crawled_not_indexed';
    case DiscoveredNotIndexed = 'discovered_not_indexed';
    case ExcludedRedirect = 'excluded_redirect';
    case ExcludedCanonical = 'excluded_canonical';
    case ExcludedBlocked = 'excluded_blocked';
    case NotIndexedOther = 'not_indexed_other';
    case Unknown = 'unknown';          // URL is unknown to Google (never discovered)
    case Error = 'error';              // inspection failed (transient/API)
    case NotInspected = 'not_inspected'; // no audit has run for this URL yet

    /** Only true indexation counts — everything else is "Google does not currently hold this URL". */
    public function indexed(): bool
    {
        return $this === self::Indexed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Indexed => 'Indexed',
            self::CrawledNotIndexed => 'Crawled — not indexed',
            self::DiscoveredNotIndexed => 'Discovered — not indexed',
            self::ExcludedRedirect => 'Excluded (redirect)',
            self::ExcludedCanonical => 'Excluded (duplicate/canonical)',
            self::ExcludedBlocked => 'Excluded (noindex/blocked)',
            self::NotIndexedOther => 'Not indexed',
            self::Unknown => 'Unknown to Google',
            self::Error => 'Inspection error',
            self::NotInspected => 'Not yet inspected',
        };
    }

    /**
     * Map GSC URL Inspection's `coverageState` (+ `verdict`) to a normalized state. `coverageState` is
     * the authoritative human phrase; we match its stable substrings and fall back to the verdict.
     */
    public static function fromInspection(?string $coverageState, ?string $verdict): self
    {
        $c = mb_strtolower(trim((string) $coverageState));

        return match (true) {
            $c === '' && strtoupper((string) $verdict) === 'PASS' => self::Indexed,
            $c === '' => self::Unknown,
            str_contains($c, 'unknown to google') => self::Unknown,
            str_contains($c, 'redirect') => self::ExcludedRedirect,
            str_contains($c, 'discovered') && str_contains($c, 'not indexed') => self::DiscoveredNotIndexed,
            str_contains($c, 'crawled') && str_contains($c, 'not indexed') => self::CrawledNotIndexed,
            str_contains($c, 'duplicate') || str_contains($c, 'alternate page') || str_contains($c, 'canonical') => self::ExcludedCanonical,
            str_contains($c, 'noindex') || str_contains($c, 'blocked') || str_contains($c, 'excluded by') || str_contains($c, 'forbidden') || str_contains($c, 'not found') => self::ExcludedBlocked,
            str_contains($c, 'indexed') => self::Indexed, // "Submitted and indexed" / "Indexed, not submitted in sitemap"
            default => self::NotIndexedOther,
        };
    }
}
