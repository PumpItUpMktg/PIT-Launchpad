<?php

namespace App\ContentEngine\Drafting;

/**
 * The sentinel-block grammar — the single source of truth for the drafter wire
 * format, shared by the prompt contracts (Drafter / PageDrafter) and the parser
 * (SentinelParser). The model emits RAW content between markers, so a multi-KB
 * HTML body never has to be JSON-escaped: there are no quotes to balance and no
 * control characters to escape. This converts draft failure from a per-character
 * lottery (one stray quote in thousands kills the whole decode) to a per-marker
 * one — a mangled block loses one slot, not the draft.
 *
 * One grammar for both kinds. A block is:
 *
 *     <<<SLOT:key>>>
 *     …raw value, verbatim…
 *     <<<END>>>
 *
 * A post is the degenerate case (a single `body` block); a page is N slot blocks.
 * Repeat a block with the same key to append another item to a repeater. Small
 * scalar fields ride the same grammar — there is no JSON sidecar.
 *
 * Key namespace:
 *   - `body`              the post article HTML (post kind)
 *   - `<slot_key>`        a kit slot value (page kind); repeat for repeaters
 *   - `seo.<field>`       title | meta_description | slug | og_title |
 *                         og_description | twitter_title | twitter_description
 *   - `image.<slot>`      an image SPEC; fields after `||`:
 *                         prompt || seo_filename || alt || title || caption
 *   - `claim`             a business assertion; fields: text || claim_id
 *   - `source`            a cited source; fields: name || url
 *   - `town`              a local town woven in
 *
 * Structured/object content (a faq item, a stat, a testimonial, a cta) carries
 * its sub-fields after the `||` field delimiter in a fixed order; the kit-aware
 * SlotShaper re-keys them. The markers are deliberately improbable in real copy.
 */
final class Sentinel
{
    public const OPEN_PREFIX = '<<<SLOT:';

    public const OPEN_SUFFIX = '>>>';

    public const CLOSE = '<<<END>>>';

    /** Sub-field delimiter inside a single block (image/claim/source/object slots). */
    public const FIELD = '||';

    /**
     * Format one block. Content is emitted verbatim — never escaped.
     */
    public static function block(string $key, string $content): string
    {
        return self::OPEN_PREFIX.$key.self::OPEN_SUFFIX."\n".$content."\n".self::CLOSE;
    }

    /**
     * Join sub-fields with the field delimiter, trimming trailing empty fields so
     * an absent optional tail (title/caption, a null url) doesn't emit dangling
     * delimiters.
     *
     * @param  list<string|null>  $fields
     */
    public static function fields(array $fields): string
    {
        while ($fields !== [] && ($fields[count($fields) - 1] === null || $fields[count($fields) - 1] === '')) {
            array_pop($fields);
        }

        return implode(' '.self::FIELD.' ', array_map(static fn ($f) => (string) $f, $fields));
    }
}
