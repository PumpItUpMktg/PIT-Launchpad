<?php

namespace App\Publishing\Blocks;

use App\Integrations\Claude\ClaudeClient;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Str;
use Throwable;

/**
 * Resolves the one-line description for a service card on the home page — NEVER null. The card pattern
 * has a blurb slot; this fills it, honouring "bring it in if it exists, else generate before publish":
 *
 *   1. The child service page's OWN description (its SEO meta, else its hero/intro slots) — when the
 *      page is already generated the card mirrors the real page.
 *   2. A previously-generated blurb cached on the page (`meta.card_blurb`) — generated once, reused on
 *      every re-publish, so the card is stable and we never pay for it twice.
 *   3. A freshly generated, KEYWORD-GROUNDED blurb (best-effort Claude, cached on success) — grounded
 *      on the page's §5 target keyword so the copy is SEO-anchored, not generic.
 *   4. A deterministic keyword/name template — the last-resort guarantee that a card is never blank,
 *      even with no keyword and no model reachable.
 *
 * Steps 1–2 are pure reads; step 3 is the only side effect (a quiet cache write) and only runs for an
 * ungenerated, un-cached child — so a fully-built site composes with no model calls at all.
 */
final class ServiceCardBlurb
{
    private const LIMIT = 155;

    public function __construct(private readonly ClaudeClient $claude) {}

    public function for(Content $page): string
    {
        $real = $this->childDescription($page);
        if ($real !== '') {
            return $real;
        }

        $cached = trim((string) ($page->meta['card_blurb'] ?? ''));
        if ($cached !== '') {
            return $cached;
        }

        return $this->generate($page);
    }

    /** The child page's real description: SEO meta first, then its own hero/intro/problem slots. */
    private function childDescription(Content $page): string
    {
        $seo = is_array($page->meta['seo'] ?? null) ? $page->meta['seo'] : [];
        $desc = trim((string) ($seo['meta_description'] ?? ''));
        if ($desc !== '') {
            return $this->oneLine($desc);
        }

        $slots = is_array($page->slot_payload) ? $page->slot_payload : [];
        foreach (['hero_subhead', 'intro', 'hero_problem', 'summary', 'body'] as $key) {
            $value = $slots[$key] ?? null;
            $text = trim(is_array($value) ? (string) ($value[0] ?? '') : (string) $value);
            if ($text !== '') {
                return $this->oneLine(strip_tags($text));
            }
        }

        return '';
    }

    /** Generate a keyword-grounded blurb (best-effort), caching a good result onto the page. */
    private function generate(Content $page): string
    {
        $name = trim((string) $page->title);
        $keyword = $this->keyword($page);

        $blurb = '';
        try {
            $blurb = $this->clean($this->claude->complete($this->prompt($name, $keyword)));
        } catch (Throwable) {
            $blurb = '';
        }

        // Model unreachable / empty → a deterministic, keyword-anchored line (never cached, so a later
        // publish with the model up can still upgrade the card to real generated copy).
        if ($blurb === '') {
            return $this->template($name, $keyword);
        }

        // Generated once → cache quietly (a card blurb is derived copy, not an audited operator edit).
        $meta = is_array($page->meta) ? $page->meta : [];
        $meta['card_blurb'] = $blurb;
        $page->forceFill(['meta' => $meta])->saveQuietly();

        return $blurb;
    }

    /** The page's target keyword text, read scope-free (a worker publish has no CurrentSite set). */
    private function keyword(Content $page): string
    {
        if ($page->target_keyword_id === null) {
            return '';
        }

        return trim((string) Keyword::withoutGlobalScope(SiteScope::class)
            ->whereKey($page->target_keyword_id)
            ->value('query'));
    }

    private function prompt(string $name, string $keyword): string
    {
        $anchor = $keyword !== '' ? "the search phrase \"{$keyword}\"" : "the service \"{$name}\"";

        return implode("\n", [
            "Write a single one-line description for a \"{$name}\" service card on a local service business's home page.",
            "Ground it on {$anchor} so it reads as SEO-relevant, but keep it natural — not keyword-stuffed.",
            'Requirements: one sentence, plain text (no quotes, no markdown, no emoji), '.self::LIMIT.' characters max, benefit-led, no fabricated specifics (no prices, guarantees, years in business, or awards).',
            'Return ONLY the sentence.',
        ]);
    }

    private function template(string $name, string $keyword): string
    {
        $subject = $keyword !== '' ? Str::ucfirst($keyword) : ($name !== '' ? $name : 'Professional service');

        return $this->oneLine("{$subject} handled by a team you can count on — reliable work and honest, upfront pricing.");
    }

    private function clean(string $text): string
    {
        $text = trim($text);
        // Models sometimes prefix a label or wrap the line in quotes — strip both before use.
        $text = (string) preg_replace('/^(description|blurb)\s*:\s*/i', '', $text);
        $text = trim($text, " \t\n\r\0\x0B\"'“”");

        return $this->oneLine(strip_tags($text));
    }

    /** Collapse to one line and truncate at a word boundary. */
    private function oneLine(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= self::LIMIT) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::LIMIT);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ' ,.;:').'…';
    }
}
