<?php

namespace App\ContentEngine\Linking;

/**
 * Deterministically weaves internal links into a drafted post body (HTML). Pure string work — no
 * DB, no model call — so the resolver decides WHAT to link and this decides WHERE, testably.
 *
 * Rules that keep the body clean and safe:
 *  - Only the FIRST unlinked occurrence of a term is wrapped (one link per target, no keyword
 *    stuffing).
 *  - A term already inside an existing <a>…</a> is left alone (never double-wrap / nest anchors).
 *  - Matching is whole-word and case-sensitive to the drafted casing, so "Trooper" links but
 *    "troopers" and mid-word hits do not.
 *  - The silo (topical) link prefers an inline mention; only if the label never appears is a single
 *    "Related: <a>…</a>" line appended — so the juice always flows even when the drafter didn't name
 *    the service, without silently reshaping copy that did.
 */
class PostLinkInjector
{
    /**
     * @param  array<string, string>  $locationLinks  town label => path (geographic juice)
     * @param  array{label: string, path: string}|null  $siloLink  the topical pillar link
     * @return array{body: string, injected: list<array{anchor: string, path: string, kind: string}>}
     */
    public function inject(string $body, array $locationLinks, ?array $siloLink): array
    {
        $injected = [];

        foreach ($locationLinks as $label => $path) {
            if ($this->linkFirst($body, (string) $label, (string) $path)) {
                $injected[] = ['anchor' => (string) $label, 'path' => (string) $path, 'kind' => 'location'];
            }
        }

        if ($siloLink !== null && trim($siloLink['label']) !== '' && trim($siloLink['path']) !== '') {
            $label = $siloLink['label'];
            $path = $siloLink['path'];
            if ($this->linkFirst($body, $label, $path)) {
                $injected[] = ['anchor' => $label, 'path' => $path, 'kind' => 'silo'];
            } elseif (! $this->alreadyLinks($body, $path)) {
                $body .= "\n".'<p>Related: <a href="'.$this->esc($path).'">'.$this->esc($label).'</a></p>';
                $injected[] = ['anchor' => $label, 'path' => $path, 'kind' => 'silo'];
            }
        }

        return ['body' => $body, 'injected' => $injected];
    }

    /**
     * Wrap the first whole-word, not-already-linked occurrence of $term in $body with a link to
     * $path. Returns true if a wrap happened. Skips when the body already links that path (idempotent
     * — re-running a draft never stacks duplicate links).
     */
    private function linkFirst(string &$body, string $term, string $path): bool
    {
        $term = trim($term);
        if ($term === '' || $path === '' || $this->alreadyLinks($body, $path)) {
            return false;
        }

        // Split on anchors so we only ever match in the non-link text between them.
        $segments = preg_split('/(<a\b[^>]*>.*?<\/a>)/is', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($segments === false) {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u';
        $anchor = '<a href="'.$this->esc($path).'">'.$this->esc($term).'</a>';

        foreach ($segments as $i => $segment) {
            // Odd indices are the captured <a>…</a> blocks — never touch them.
            if ($i % 2 === 1) {
                continue;
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

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
