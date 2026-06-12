<?php

namespace App\ContentEngine\Drafting;

/**
 * Renders the shared tail of the drafter output contract — the metadata blocks
 * (seo / image / claim / source / town) that ride the same sentinel grammar for
 * both kinds. Single-sourced so the post Drafter and the PageDrafter describe the
 * wire format identically; the content blocks (a post body vs kit slots) are the
 * only per-kind part, passed in.
 */
final class SentinelContract
{
    /**
     * @param  string  $intro  the per-kind preamble (RAW-between-markers rule)
     * @param  string  $contentBlocks  the per-kind content-block example
     */
    public static function describe(string $intro, string $contentBlocks): string
    {
        $field = Sentinel::FIELD;

        return implode("\n", [
            $intro,
            '',
            $contentBlocks,
            '',
            'Then emit these metadata blocks (fields after '.$field.' are in the stated order; repeat a block to add another item):',
            '',
            "<<<SLOT:seo.title>>>\nplain & specific, ≤60 chars — NO publication/source names, NO \"| Brand\" suffix, NO attribution (that lives in the body)\n<<<END>>>",
            "<<<SLOT:seo.meta_description>>>\n…\n<<<END>>>",
            "<<<SLOT:seo.slug>>>\n…\n<<<END>>>",
            '(optional: seo.og_title, seo.og_description, seo.twitter_title, seo.twitter_description)',
            '',
            'For each generated image — a SPEC, never a rendered asset:',
            "<<<SLOT:image.<slot_key>>>>\nprompt {$field} seo_filename {$field} alt {$field} title {$field} caption\n<<<END>>>",
            '',
            'For each business assertion you made (cite the claim id from the pool):',
            "<<<SLOT:claim>>>\nassertion as written {$field} claim_id\n<<<END>>>",
            '',
            'For each source you referenced (attribution only):',
            "<<<SLOT:source>>>\nsource name {$field} canonical url\n<<<END>>>",
            '',
            'For each local town you wove in (only if localization was permitted):',
            "<<<SLOT:town>>>\ntown name\n<<<END>>>",
        ]);
    }
}
