<?php

namespace App\Publishing\Links;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Publishing\Blocks\BlockContentAssembler;

/**
 * The hub→spoke ordering guard (report fix 2). A hub's internal-link grid is baked from the spoke pages
 * in its silo REGARDLESS of whether they're live yet ({@see BlockContentAssembler}
 * keys the grid on `silo_id` + `page_type=Service`, not published state), so pushing a hub before its
 * spokes publishes it with links that 404. Likewise a town page nests under its GBP location hub, whose
 * page must be live for the town's URL + breadcrumb to resolve.
 *
 * This computes, for a page about to publish, the targets that aren't live yet — the board turns a
 * non-empty result into a confirmation interstitial (push the missing pages first, or override
 * explicitly). Pure reads; changes nothing.
 */
final class HubSpokeGuard
{
    /**
     * The pages this page would link into that aren't published yet — the dead links a push would create.
     * Empty when the push is safe.
     *
     * @return list<array{id: string, title: string, kind: string}>
     */
    public function unpublishedTargets(Content $page): array
    {
        return match (true) {
            $page->page_type === PageType::Hub => $this->unpublishedSpokes($page),
            $page->page_type === PageType::Location && $page->parent_content_id !== null => $this->unpublishedParent($page),
            default => [],
        };
    }

    public function blocks(Content $page): bool
    {
        return $this->unpublishedTargets($page) !== [];
    }

    /**
     * A hub's grid targets (the service spokes in its silo) that aren't published yet.
     *
     * @return list<array{id: string, title: string, kind: string}>
     */
    private function unpublishedSpokes(Content $hub): array
    {
        if ($hub->silo_id === null) {
            return [];
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $hub->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Service->value)
            ->where('silo_id', $hub->silo_id)
            ->whereNotNull('slug')
            ->where('status', '!=', ContentStatus::Published->value)
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Content $c): array => ['id' => (string) $c->id, 'title' => (string) $c->title, 'kind' => 'spoke'])
            ->all();
    }

    /**
     * A town page's parent GBP location hub page when it isn't published yet.
     *
     * @return list<array{id: string, title: string, kind: string}>
     */
    private function unpublishedParent(Content $town): array
    {
        $parent = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $town->site_id)
            ->whereKey($town->parent_content_id)
            ->first(['id', 'title', 'status']);

        if ($parent === null || $parent->status === ContentStatus::Published) {
            return [];
        }

        return [['id' => (string) $parent->id, 'title' => (string) $parent->title, 'kind' => 'location hub']];
    }
}
