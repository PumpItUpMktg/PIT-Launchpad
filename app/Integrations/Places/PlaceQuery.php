<?php

namespace App\Integrations\Places;

/**
 * Turns operator input — a typed business name OR a pasted Google Maps / GBP URL
 * — into a Text Search query string. A Maps URL's `/place/<Name>/` segment is the
 * best free-text signal; otherwise the input is used as-is.
 */
final class PlaceQuery
{
    public static function normalize(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $input) !== 1) {
            return $input; // a plain name/address
        }

        // Maps URL: …/place/<Business+Name>/@lat,lng,… → the place segment.
        if (preg_match('#/place/([^/@]+)#', $input, $m) === 1) {
            return trim(str_replace('+', ' ', urldecode($m[1])));
        }

        // A search URL with a ?q= query.
        $parts = parse_url($input);
        parse_str($parts['query'] ?? '', $query);
        if (isset($query['q']) && is_string($query['q']) && trim($query['q']) !== '') {
            return trim($query['q']);
        }

        return $input;
    }
}
