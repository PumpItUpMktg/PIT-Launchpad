<?php

namespace App\Publishing\Links;

use App\Enums\LinkFindingType;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Site;

/**
 * Audits a site's published internal linking against the {@see InternalLinkGraph} and reports three
 * fixable conditions:
 *
 *  - ORPHAN      — a published page nothing links to (and the chrome doesn't either): undiscoverable.
 *  - DEAD END    — a published page that links to nothing: it passes no authority onward.
 *  - OPPORTUNITY — a page whose copy already NAMES another page's ranking term but doesn't link it — a
 *                  concrete link to add (this is where cross-silo juice is currently left on the table).
 *
 * Pure + control-plane only. The Home page is excluded (it's the root; its links live in the chrome).
 * Opportunities key on a destination's target KEYWORD (a real ranking term), whole-word, so the signal
 * is precise rather than noisy — and each is capped per page.
 */
final class InternalLinkAuditor
{
    private const MAX_OPPORTUNITIES_PER_PAGE = 3;

    private const MIN_TERM_LENGTH = 6;

    public function __construct(private readonly InternalLinkGraph $graph) {}

    /**
     * @return list<LinkFinding>
     */
    public function audit(Site $site): array
    {
        $graph = $this->graph->build($site);
        $terms = $this->destinationTerms($graph);

        $findings = [];
        foreach ($graph->pages as $id => $page) {
            $id = (string) $id;
            if ($page->page_type === PageType::Home) {
                continue; // the root — linked from the chrome on every page, never an orphan/dead-end
            }

            if (! $graph->isChromeLinked($page) && $graph->inbound($id) === []) {
                $findings[] = new LinkFinding(
                    type: LinkFindingType::Orphan,
                    contentId: $id,
                    url: $this->path($page),
                    title: (string) $page->title,
                    detail: 'No other page links to this one — add an inbound link so it can be found and ranked.',
                    suggestedContentId: $this->suggestHub($page, $graph),
                    suggestedLabel: $this->suggestHubLabel($page, $graph),
                );
            }

            if ($graph->outbound($id) === []) {
                $hubId = $this->suggestHub($page, $graph);
                $findings[] = new LinkFinding(
                    type: LinkFindingType::DeadEnd,
                    contentId: $id,
                    url: $this->path($page),
                    title: (string) $page->title,
                    detail: 'This page links to nothing — link it up to its topic hub so it passes authority onward.',
                    suggestedContentId: $hubId,
                    suggestedLabel: $hubId !== null ? $this->suggestHubLabel($page, $graph) : null,
                );
            }

            $findings = array_merge($findings, $this->opportunities($id, $page, $graph, $terms));
        }

        return $findings;
    }

    /**
     * Ranking-term → destination-id map: each published service / location / hub page keyed on its
     * target keyword (the precise anchor), lowercased, long enough to be specific.
     *
     * @return array<string, string>
     */
    private function destinationTerms(InternalLinkGraph $graph): array
    {
        $terms = [];
        foreach ($graph->pages as $id => $page) {
            if (! in_array($page->page_type, [PageType::Service, PageType::Location, PageType::Hub], true)) {
                continue;
            }
            $term = mb_strtolower(trim((string) ($page->targetKeyword->query ?? '')));
            if (mb_strlen($term) >= self::MIN_TERM_LENGTH) {
                $terms[$term] = (string) $id;
            }
        }

        return $terms;
    }

    /**
     * @param  array<string, string>  $terms
     * @return list<LinkFinding>
     */
    private function opportunities(string $id, Content $page, InternalLinkGraph $graph, array $terms): array
    {
        $text = $graph->text($id);
        if ($text === '') {
            return [];
        }

        $found = [];
        foreach ($terms as $term => $destId) {
            if ($destId === $id || $graph->linksTo($id, $destId) || isset($found[$destId])) {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $text) === 1) {
                $dest = $graph->pages->get($destId);
                $found[$destId] = new LinkFinding(
                    type: LinkFindingType::Opportunity,
                    contentId: $id,
                    url: $this->path($page),
                    title: (string) $page->title,
                    detail: 'Names “'.$term.'” but doesn’t link it.',
                    suggestedContentId: $destId,
                    suggestedLabel: $dest !== null ? (string) $dest->title : null,
                );
            }
            if (count($found) >= self::MAX_OPPORTUNITIES_PER_PAGE) {
                break;
            }
        }

        return array_values($found);
    }

    /** The page's topic hub (its parent nesting page), the natural inbound/outbound target for a fix. */
    private function suggestHub(Content $page, InternalLinkGraph $graph): ?string
    {
        $parentId = (string) ($page->parent_content_id ?? '');

        return $parentId !== '' && $graph->pages->has($parentId) ? $parentId : null;
    }

    private function suggestHubLabel(Content $page, InternalLinkGraph $graph): ?string
    {
        $hubId = $this->suggestHub($page, $graph);

        return $hubId !== null ? (string) $graph->pages->get($hubId)?->title : null;
    }

    private function path(Content $page): string
    {
        return '/'.ltrim((string) $page->slug, '/');
    }
}
