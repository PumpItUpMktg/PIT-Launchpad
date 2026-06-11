<?php

namespace Tests\Support;

use App\ContentEngine\Drafting\Sentinel;
use App\ContentEngine\Drafting\SentinelParser;

/**
 * The test-side inverse of {@see SentinelParser}:
 * renders a logical drafter payload (the same associative shape the JSON
 * fixtures used) into the sentinel wire format the model now emits. Keeping the
 * fixtures' INPUT identical means the drafting tests assert on parsed results,
 * not on the wire format — only this one encoder knows the grammar.
 *
 * Object repeater items (faq {question,answer}, stat {value,label}, …) are joined
 * by the `||` field delimiter in their array order, mirroring the order the
 * SlotShaper re-keys.
 */
final class SentinelEncoder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        $blocks = [];

        if (isset($payload['body'])) {
            $blocks[] = Sentinel::block('body', (string) $payload['body']);
        }

        foreach (($payload['slots'] ?? []) as $key => $value) {
            foreach (self::items($value) as $item) {
                $blocks[] = Sentinel::block((string) $key, $item);
            }
        }

        foreach (($payload['seo'] ?? []) as $field => $value) {
            $blocks[] = Sentinel::block('seo.'.$field, (string) $value);
        }

        foreach (($payload['images'] ?? []) as $image) {
            $blocks[] = Sentinel::block('image.'.($image['slot'] ?? ''), Sentinel::fields([
                $image['prompt'] ?? '',
                $image['seo_filename'] ?? '',
                $image['alt'] ?? '',
                $image['title'] ?? null,
                $image['caption'] ?? null,
            ]));
        }

        foreach (($payload['claims_used'] ?? []) as $claim) {
            $blocks[] = Sentinel::block('claim', Sentinel::fields([$claim['text'] ?? '', $claim['claim_id'] ?? null]));
        }

        foreach (($payload['sources_cited'] ?? []) as $source) {
            $blocks[] = Sentinel::block('source', Sentinel::fields([$source['name'] ?? '', $source['url'] ?? null]));
        }

        foreach (($payload['towns'] ?? []) as $town) {
            $blocks[] = Sentinel::block('town', (string) $town);
        }

        return implode("\n", $blocks);
    }

    /**
     * A scalar slot → one block; a list → one block per element; an object element
     * → its values joined by the field delimiter.
     *
     * @return list<string>
     */
    private static function items(mixed $value): array
    {
        if (! is_array($value)) {
            return [(string) $value];
        }

        $out = [];
        foreach ($value as $element) {
            $out[] = is_array($element)
                ? Sentinel::fields(array_values($element))
                : (string) $element;
        }

        return $out;
    }
}
