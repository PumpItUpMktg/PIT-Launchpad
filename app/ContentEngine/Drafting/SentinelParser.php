<?php

namespace App\ContentEngine\Drafting;

/**
 * Parses the sentinel-block wire format (see {@see Sentinel}) into the array
 * shape {@see DraftPayload::fromArray()} consumes. It is FORMAT-ONLY and
 * kind-agnostic: it never sees the kit. A post yields `body`; a page yields raw
 * `slots` (a single block → a scalar; a repeated key → a list) that the kit-aware
 * {@see SlotShaper} re-keys. Reserved keys (`seo.*`, `image.*`, `claim`, `source`,
 * `town`) route to the metadata buckets.
 *
 * Extraction is per-marker and tolerant: surrounding prose, a preamble, or a
 * stray `<<<` that is not a real marker are ignored, and a single mangled block
 * is simply dropped — the rest of the draft survives. There is no escaping to get
 * wrong, so the unescaped-quote / raw-control-char failures that plagued the JSON
 * encoding cannot occur here.
 */
final class SentinelParser
{
    private const BLOCK = '/'.'<<<SLOT:\s*([A-Za-z0-9_.:\-]+?)\s*>>>(.*?)<<<END>>>'.'/s';

    /**
     * @return array<string, mixed>
     */
    public static function parse(string $raw): array
    {
        if (preg_match_all(self::BLOCK, $raw, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $body = null;
        /** @var array<string, list<string>> $slots */
        $slots = [];
        $seo = [];
        $images = [];
        $claims = [];
        $sources = [];
        $towns = [];

        foreach ($matches as $match) {
            $key = $match[1];
            $content = trim($match[2]);

            if ($key === 'body') {
                $body = $content;
            } elseif (str_starts_with($key, 'seo.')) {
                $seo[substr($key, 4)] = $content;
            } elseif (str_starts_with($key, 'image.')) {
                $images[] = self::image(substr($key, 6), $content);
            } elseif ($key === 'claim') {
                $claim = self::claim($content);
                if ($claim !== null) {
                    $claims[] = $claim;
                }
            } elseif ($key === 'source') {
                $source = self::source($content);
                if ($source !== null) {
                    $sources[] = $source;
                }
            } elseif ($key === 'town') {
                if ($content !== '') {
                    $towns[] = $content;
                }
            } else {
                $slots[$key][] = $content;
            }
        }

        $result = [];
        if ($body !== null) {
            $result['body'] = $body;
        }
        if ($slots !== []) {
            // A single block is a scalar; a repeated key is a list. The kit-aware
            // SlotShaper wraps a lone repeater item back into a list as needed.
            $result['slots'] = array_map(
                static fn (array $values) => count($values) === 1 ? $values[0] : $values,
                $slots,
            );
        }
        if ($seo !== []) {
            $result['seo'] = $seo;
        }
        if ($images !== []) {
            $result['images'] = $images;
        }
        if ($claims !== []) {
            $result['claims_used'] = $claims;
        }
        if ($sources !== []) {
            $result['sources_cited'] = $sources;
        }
        if ($towns !== []) {
            $result['towns'] = $towns;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function image(string $slot, string $content): array
    {
        $f = self::fields($content);

        return [
            'slot' => $slot,
            'prompt' => $f[0] ?? '',
            'seo_filename' => $f[1] ?? '',
            'alt' => $f[2] ?? '',
            'title' => self::nullable($f[3] ?? null),
            'caption' => self::nullable($f[4] ?? null),
        ];
    }

    /**
     * @return array{text: string, claim_id: string|null}|null
     */
    private static function claim(string $content): ?array
    {
        $f = self::fields($content);
        $text = $f[0] ?? '';

        if ($text === '') {
            return null;
        }

        return ['text' => $text, 'claim_id' => self::nullable($f[1] ?? null)];
    }

    /**
     * @return array{name: string, url: string|null}|null
     */
    private static function source(string $content): ?array
    {
        $f = self::fields($content);
        $name = $f[0] ?? '';

        if ($name === '') {
            return null;
        }

        return ['name' => $name, 'url' => self::nullable($f[1] ?? null)];
    }

    /**
     * @return list<string>
     */
    private static function fields(string $content): array
    {
        return array_map('trim', explode(Sentinel::FIELD, $content));
    }

    private static function nullable(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }
}
