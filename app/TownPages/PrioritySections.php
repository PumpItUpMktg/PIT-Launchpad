<?php

namespace App\TownPages;

use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The drafted priority sections STORED on a town page (`meta.priority_sections`, written by
 * {@see PrioritySectionWriter}), read back for render and for the meta-blob — filtered to the keywords
 * that are STILL priority, so a keyword taken off the list drops from every page on its next push without
 * a redraft. Each entry: keyword_id, keyword, service_id, heading, body, faqs ({question, answer} × 2),
 * drafted_at.
 */
final class PrioritySections
{
    public const META_KEY = 'priority_sections';

    public function __construct(private readonly PriorityKeywords $priority) {}

    /**
     * @return list<array{keyword_id: string, keyword: string, service_id: string|null, heading: string, body: string, faqs: list<array{question: string, answer: string}>, drafted_at: string|null}>
     */
    public function stored(Content $page): array
    {
        $raw = is_array($page->meta) ? ($page->meta[self::META_KEY] ?? null) : null;
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || trim((string) ($entry['heading'] ?? '')) === '' || trim((string) ($entry['body'] ?? '')) === '') {
                continue;
            }
            $faqs = [];
            foreach (is_array($entry['faqs'] ?? null) ? $entry['faqs'] : [] as $faq) {
                if (is_array($faq) && trim((string) ($faq['question'] ?? '')) !== '' && trim((string) ($faq['answer'] ?? '')) !== '') {
                    $faqs[] = ['question' => trim((string) $faq['question']), 'answer' => trim((string) $faq['answer'])];
                }
            }
            $out[] = [
                'keyword_id' => (string) ($entry['keyword_id'] ?? ''),
                'keyword' => (string) ($entry['keyword'] ?? ''),
                'service_id' => isset($entry['service_id']) && $entry['service_id'] !== '' ? (string) $entry['service_id'] : null,
                'heading' => trim((string) $entry['heading']),
                'body' => trim((string) $entry['body']),
                'faqs' => $faqs,
                'drafted_at' => isset($entry['drafted_at']) ? (string) $entry['drafted_at'] : null,
            ];
        }

        return $out;
    }

    /**
     * The sections that RENDER: stored ones whose keyword is still a priority of the site, in rank order.
     *
     * @return list<array{keyword_id: string, keyword: string, service_id: string|null, heading: string, body: string, faqs: list<array{question: string, answer: string}>, drafted_at: string|null}>
     */
    public function live(Content $page): array
    {
        $stored = $this->stored($page);
        if ($stored === []) {
            return [];
        }
        $site = Site::withoutGlobalScope(SiteScope::class)->find($page->site_id);
        if ($site === null) {
            return [];
        }
        $rank = [];
        foreach ($this->priority->for($site) as $i => $keyword) {
            $rank[(string) $keyword->id] = $i;
        }
        $live = array_values(array_filter($stored, fn (array $s): bool => isset($rank[$s['keyword_id']])));
        usort($live, fn (array $a, array $b): int => $rank[$a['keyword_id']] <=> $rank[$b['keyword_id']]);

        return $live;
    }

    /**
     * A page's FAQ items with the live priority Q&As appended, capped to the kit's maximum — the one list
     * both the rendered accordion and the plugin's FAQPage schema read.
     *
     * @param  list<array<string, mixed>>  $faqs  the page's own drafted items
     * @return list<array<string, mixed>>
     */
    public function withFaqs(Content $page, array $faqs, int $max = 8): array
    {
        $extra = [];
        foreach ($this->live($page) as $section) {
            foreach ($section['faqs'] as $faq) {
                $extra[] = $faq;
            }
        }
        if ($extra === []) {
            return $faqs;
        }
        // The priority Q&As are the reason the page is being pushed; the page's own tail gives way.
        $keep = max(0, $max - count($extra));

        return array_merge(array_slice($faqs, 0, $keep), array_slice($extra, 0, $max));
    }

    /** The keyword ids a stored page already carries (any state). @return list<string> */
    public function storedKeywordIds(Content $page): array
    {
        return array_values(array_unique(array_column($this->stored($page), 'keyword_id')));
    }

    /** @param  list<Keyword>  $keywords @return list<string> */
    public static function ids(array $keywords): array
    {
        return array_map(fn (Keyword $k): string => (string) $k->id, $keywords);
    }
}
