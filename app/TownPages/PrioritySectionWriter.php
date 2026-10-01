<?php

namespace App\TownPages;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Publishing\Blocks\LocationSubject;

/**
 * Stores a town page's drafted priority sections on `meta.priority_sections` — the ONE place they live
 * (the page's own slots are untouched, so taking a keyword off the list or redrafting never disturbs the
 * drafter's copy). Every section passes {@see SectionUniqueness} against the same keyword's sections on
 * the site's other town pages first; a templated one is refused and reported, never stored.
 */
final class PrioritySectionWriter
{
    public function __construct(
        private readonly PrioritySections $sections,
        private readonly LocationSubject $subject,
    ) {}

    /**
     * @param  list<array{keyword_id: string, keyword: string, service_id: string|null, heading: string, body: string, faqs: list<array{question: string, answer: string}>}>  $drafted
     * @param  list<Keyword>  $expected  the keywords this town should carry — a stored section for any other keyword is dropped
     * @return array{stored: list<string>, refused: list<array{keyword_id: string, similarity: float}>, dropped: list<string>}
     */
    public function write(Content $page, array $drafted, array $expected): array
    {
        $expectedIds = array_fill_keys(PrioritySections::ids($expected), true);
        ['label' => $town] = $this->subject->resolve($page);
        $existing = [];
        foreach ($this->sections->stored($page) as $entry) {
            $existing[$entry['keyword_id']] = $entry;
        }

        $stored = [];
        $refused = [];
        foreach ($drafted as $section) {
            if (! isset($expectedIds[$section['keyword_id']])) {
                continue;
            }
            $check = SectionUniqueness::check($section['body'], $town, $this->othersFor($page, $section['keyword_id']));
            if (! $check['ok']) {
                $refused[] = ['keyword_id' => $section['keyword_id'], 'similarity' => $check['similarity']];

                continue;
            }
            $existing[$section['keyword_id']] = $section + ['drafted_at' => now()->toIso8601String()];
            $stored[] = $section['keyword_id'];
        }

        $dropped = [];
        $keep = [];
        foreach ($existing as $id => $entry) {
            if (isset($expectedIds[$id])) {
                $keep[] = $entry;
            } else {
                $dropped[] = (string) $id;
            }
        }

        $meta = is_array($page->meta) ? $page->meta : [];
        $meta[PrioritySections::META_KEY] = $keep;
        $meta['priority_sections_at'] = now()->toIso8601String();
        unset($meta['priority_sections_error'], $meta[PrioritySectionStatus::QUEUED_KEY]);
        $page->forceFill(['meta' => $meta])->save();

        return ['stored' => $stored, 'refused' => $refused, 'dropped' => $dropped];
    }

    /** Record why a page got no sections this run (the plan shows it; the next run retries). */
    public function fail(Content $page, string $reason): void
    {
        $meta = is_array($page->meta) ? $page->meta : [];
        $meta['priority_sections_error'] = mb_substr($reason, 0, 500);
        unset($meta[PrioritySectionStatus::QUEUED_KEY]);
        $page->forceFill(['meta' => $meta])->save();
    }

    /**
     * The same keyword's sections on the site's OTHER town pages (the most recent 40), with their towns.
     *
     * @return list<array{body: string, town: string}>
     */
    private function othersFor(Content $page, string $keywordId): array
    {
        $others = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereKeyNot($page->id)
            ->whereNotNull('parent_location_id')
            ->whereNotNull('meta')
            ->orderByDesc('updated_at')
            ->limit(400)
            ->get(['id', 'site_id', 'title', 'geo_id', 'meta', 'location_id', 'parent_location_id']);

        $out = [];
        foreach ($others as $other) {
            foreach ($this->sections->stored($other) as $entry) {
                if ($entry['keyword_id'] !== $keywordId) {
                    continue;
                }
                ['label' => $otherTown] = $this->subject->resolve($other);
                $out[] = ['body' => $entry['body'], 'town' => $otherTown];
                if (count($out) >= 40) {
                    return $out;
                }
            }
        }

        return $out;
    }
}
