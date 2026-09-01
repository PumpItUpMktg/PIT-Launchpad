<?php

namespace App\Integrations\Citations;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads a listing's NAP by fetching the page and parsing it — schema.org JSON-LD first (most directories expose
 * a LocalBusiness/Organization block with `telephone` + `address`), then a phone-number regex fallback. Best
 * effort: on a network error, a non-200, or a page that yields nothing (some majors block server fetches), it
 * returns null and the listing stays "needs review" rather than being guessed. Anti-scraping directories are the
 * case for a DataForSEO Business Data adapter binding later — same interface.
 */
final class HttpListingVerifier implements ListingVerifier
{
    public function __construct(private readonly int $timeout = 10) {}

    public function verify(string $directoryDomain, string $url): ?VerifiedListing
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LaunchpadCitations/1.0)'])
                ->get($url);
            if (! $response->ok()) {
                return null;
            }
            $html = $response->body();
        } catch (Throwable) {
            return null;
        }

        $listing = $this->fromJsonLd($html);
        if ($listing !== null && ! $listing->isEmpty()) {
            return $listing;
        }

        $phone = $this->firstPhone($html);

        return $phone !== null ? new VerifiedListing(phone: $phone) : null;
    }

    /** Parse the first schema.org business node carrying a phone or address out of the page's JSON-LD blocks. */
    private function fromJsonLd(string $html): ?VerifiedListing
    {
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $block) {
            $decoded = json_decode(trim($block), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->nodes($decoded) as $node) {
                $phone = $this->cleanPhone((string) ($node['telephone'] ?? ''));
                $address = $this->flattenAddress($node['address'] ?? null);
                $name = trim((string) ($node['name'] ?? '')) ?: null;

                if ($phone !== null || $address !== null) {
                    return new VerifiedListing(name: $name, address: $address, phone: $phone);
                }
            }
        }

        return null;
    }

    /**
     * Flatten a decoded JSON-LD payload into candidate business nodes (handles a single object, a top-level
     * list, and the common `@graph` wrapper).
     *
     * @param  array<mixed>  $decoded
     * @return list<array<string, mixed>>
     */
    private function nodes(array $decoded): array
    {
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            $decoded = $decoded['@graph'];
        }

        $isList = array_is_list($decoded);
        $candidates = $isList ? $decoded : [$decoded];

        $out = [];
        foreach ($candidates as $node) {
            if (is_array($node)) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private function flattenAddress(mixed $address): ?string
    {
        if (is_string($address)) {
            return trim($address) ?: null;
        }
        if (! is_array($address)) {
            return null;
        }

        $parts = array_filter([
            (string) ($address['streetAddress'] ?? ''),
            (string) ($address['addressLocality'] ?? ''),
            (string) ($address['addressRegion'] ?? ''),
            (string) ($address['postalCode'] ?? ''),
        ], fn (string $p): bool => trim($p) !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function cleanPhone(string $raw): ?string
    {
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

    /** North-American phone as a last resort when there's no structured data. */
    private function firstPhone(string $html): ?string
    {
        if (preg_match('#(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}#', $html, $m)) {
            return trim($m[0]);
        }

        return null;
    }
}
