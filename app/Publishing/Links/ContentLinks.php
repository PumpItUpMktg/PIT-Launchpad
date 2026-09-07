<?php

namespace App\Publishing\Links;

use App\Models\Content;

/**
 * Extracts the INTERNAL links a page carries in its rendered content — the one place that reads hrefs out
 * of a Content's body + slot payload and normalizes a path. Shared by {@see DeadLinkAudit} (the count) and
 * {@see InternalLinkValidator} (the pre-publish check) so both see the same links the same way.
 */
final class ContentLinks
{
    /**
     * Every INTERNAL link in a page's content, as [raw href => normalized path]. Scans the post body and
     * the slot payload's raw string leaves (NOT json_encode, which escapes the attribute quotes and hides
     * every link). External / anchor / mailto / tel links are skipped.
     *
     * @return array<string, string>
     */
    public function internalPaths(Content $content): array
    {
        $haystack = (string) ($content->body ?? '');
        $payload = $content->slot_payload;
        if (is_array($payload) && $payload !== []) {
            array_walk_recursive($payload, function ($value) use (&$haystack): void {
                if (is_string($value)) {
                    $haystack .= ' '.$value;
                }
            });
        }
        if ($haystack === '' || ! preg_match_all('/href=["\']([^"\']+)["\']/i', $haystack, $m)) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $href) {
            $path = $this->internalPath($href);
            if ($path !== null) {
                $out[$href] = $path;
            }
        }

        return $out;
    }

    /** Redirect/path form: strip query+fragment, leading slash, no trailing slash, lowercased. */
    public function normalizePath(string $value): string
    {
        $path = (string) parse_url($value, PHP_URL_PATH);

        return mb_strtolower('/'.trim($path, '/'));
    }

    /** Normalized path for an INTERNAL href, or null for external / anchor / mailto / tel links. */
    private function internalPath(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }

        // Absolute / protocol-relative URLs are external for this baked-relative-link check.
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://') || str_starts_with($href, '//')) {
            return null;
        }

        if (! str_starts_with($href, '/')) {
            return null; // a bare fragment / relative token we don't resolve
        }

        return $this->normalizePath($href);
    }
}
