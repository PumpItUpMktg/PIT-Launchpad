<?php

namespace App\Publishing\Links;

use App\Enums\ContentKind;
use App\Models\Content;

/**
 * Weaves a single internal link into a page's OWN drafted copy — the corrective counterpart to the
 * audit. Given a term the page already uses and the path it should point at, it wraps the FIRST
 * unlinked whole-word occurrence (never mid-word, never inside an existing anchor, never a second
 * copy). For a post it edits the `body`; for a page it edits the one slot value that carries the
 * mention — so the link lands in real, rendered prose, not an arbitrary appended blob. Idempotent:
 * a term already linked to that path is left untouched. Returns whether it changed anything (it saves
 * only when it did).
 */
final class LinkInjector
{
    public function inject(Content $content, string $term, string $path): bool
    {
        $term = trim($term);
        $path = trim($path);
        if ($term === '' || $path === '') {
            return false;
        }

        if ($content->kind === ContentKind::Post) {
            $body = is_string($content->body) ? $content->body : '';
            if ($this->linkFirst($body, $term, $path)) {
                $content->forceFill(['body' => $body])->save();

                return true;
            }

            return false;
        }

        $slots = is_array($content->slot_payload) ? $content->slot_payload : [];
        if ($this->injectIntoSlots($slots, $term, $path)) {
            $content->forceFill(['slot_payload' => $slots])->save();

            return true;
        }

        return false;
    }

    /**
     * Add a "Related: {label}" link when the page doesn't already NAME the target — the fallback for an
     * orphan / dead-end fix, where there's no existing mention to wrap. Appends to the post body, or to
     * the page's LONGEST prose slot (the one most likely to render as body copy). Idempotent.
     */
    public function appendRelated(Content $content, string $label, string $path): bool
    {
        $label = trim($label);
        $path = trim($path);
        if ($label === '' || $path === '') {
            return false;
        }
        $snippet = '<p class="lp-related-inline">Related: <a href="'.$this->esc($path).'">'.$this->esc($label).'</a></p>';

        if ($content->kind === ContentKind::Post) {
            $body = is_string($content->body) ? $content->body : '';
            if ($this->alreadyLinks($body, $path)) {
                return false;
            }
            $content->forceFill(['body' => trim($body.' '.$snippet)])->save();

            return true;
        }

        $slots = is_array($content->slot_payload) ? $content->slot_payload : [];
        $key = $this->longestProseKey($slots);
        if ($key === null || $this->alreadyLinks((string) $slots[$key], $path)) {
            return false;
        }
        $slots[$key] = trim((string) $slots[$key].' '.$snippet);
        $content->forceFill(['slot_payload' => $slots])->save();

        return true;
    }

    /** The top-level slot key with the longest string value — the page's main prose block. */
    private function longestProseKey(array $slots): ?string
    {
        $best = null;
        $len = 0;
        foreach ($slots as $key => $value) {
            if (is_string($value) && mb_strlen($value) > $len) {
                $len = mb_strlen($value);
                $best = (string) $key;
            }
        }

        return $best;
    }

    /**
     * Walk the slot payload and link the term in the FIRST string leaf that carries an unlinked
     * occurrence. Mutates $slots in place.
     *
     * @param  array<int|string, mixed>  $slots
     */
    private function injectIntoSlots(array &$slots, string $term, string $path): bool
    {
        foreach ($slots as $key => $value) {
            if (is_string($value)) {
                $text = $value;
                if ($this->linkFirst($text, $term, $path)) {
                    $slots[$key] = $text;

                    return true;
                }
            } elseif (is_array($value)) {
                if ($this->injectIntoSlots($value, $term, $path)) {
                    $slots[$key] = $value;

                    return true;
                }
            }
        }

        return false;
    }

    /** Wrap the first unlinked, whole-word occurrence of $term with a link to $path. (From PostLinkInjector.) */
    private function linkFirst(string &$body, string $term, string $path): bool
    {
        if ($this->alreadyLinks($body, $path)) {
            return false;
        }

        $segments = preg_split('/(<a\b[^>]*>.*?<\/a>)/is', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($segments === false) {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu';
        $anchor = '<a href="'.$this->esc($path).'">'.$this->esc($term).'</a>';

        foreach ($segments as $i => $segment) {
            if ($i % 2 === 1) {
                continue; // an existing <a>…</a> block — never touch
            }
            $replaced = preg_replace($pattern, $anchor, $segment, 1, $count);
            if ($replaced !== null && $count > 0) {
                $segments[$i] = $replaced;
                $body = implode('', $segments);

                return true;
            }
        }

        return false;
    }

    private function alreadyLinks(string $body, string $path): bool
    {
        return str_contains($body, 'href="'.$this->esc($path).'"')
            || str_contains($body, "href='".$this->esc($path)."'");
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
