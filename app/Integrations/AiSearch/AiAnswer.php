<?php

namespace App\Integrations\AiSearch;

/**
 * A normalized answer from an AI search engine — the vendor-agnostic contract every engine adapter
 * (Claude web-search, later Perplexity/OpenAI/Gemini) maps its response into. `text` is the answer prose;
 * `citations` are the sources the engine cited (url + title), the raw material GEO detection scans for the
 * brand and competitors.
 */
final class AiAnswer
{
    /**
     * @param  list<array{url: string, title: string}>  $citations
     */
    public function __construct(
        public readonly string $text,
        public readonly array $citations = [],
    ) {}

    /** All cited URLs, lower-cased, for host/domain matching. @return list<string> */
    public function citationUrls(): array
    {
        return array_values(array_filter(array_map(
            fn (array $c): string => mb_strtolower(trim($c['url'] ?? '')),
            $this->citations,
        ), fn (string $u): bool => $u !== ''));
    }
}
