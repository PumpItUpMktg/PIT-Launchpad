<?php

namespace App\Publishing\Links;

use App\Enums\ContentStatus;
use App\Enums\LinkFindingType;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Applies ONE approved internal-link finding, then re-publishes the page it edited. Corrections are
 * minimal, real content edits (the same idempotent-by-ULID publish the rest of the platform uses):
 *
 *  - OPPORTUNITY — link the term the source page already names → the destination (else append a
 *    "Related:" link when the mention has since gone).
 *  - DEAD END    — give the page an outbound link to its topic hub (or home).
 *  - ORPHAN      — add an inbound link FROM the page's topic hub (or home) to it.
 *
 * Nothing is auto-applied — the operator approves each finding on the Internal Links surface. A fix
 * with no sensible target (an orphan whose silo has no hub, no home page) is reported skipped so the
 * operator can place the link by hand.
 */
final class InternalLinkFixer
{
    public function __construct(private readonly LinkInjector $injector) {}

    public function fix(Site $site, LinkFinding $finding, ?string $actorId = null): LinkFixResult
    {
        $page = $this->owned($site, $finding->contentId);
        if ($page === null) {
            return LinkFixResult::skipped('That page no longer exists.');
        }

        return match ($finding->type) {
            LinkFindingType::Opportunity => $this->fixOpportunity($site, $page, $finding, $actorId),
            LinkFindingType::DeadEnd => $this->fixDeadEnd($site, $page, $actorId),
            LinkFindingType::Orphan => $this->fixOrphan($site, $page, $actorId),
        };
    }

    private function fixOpportunity(Site $site, Content $page, LinkFinding $finding, ?string $actorId): LinkFixResult
    {
        $dest = $finding->suggestedContentId !== null ? $this->owned($site, $finding->suggestedContentId) : null;
        if ($dest === null) {
            return LinkFixResult::skipped('The page to link to no longer exists.');
        }

        $term = trim((string) ($dest->targetKeyword->query ?? ''));
        $path = $this->path($dest);
        $label = (string) $dest->title;

        $changed = ($term !== '' && $this->injector->inject($page, $term, $path))
            || $this->injector->appendRelated($page, $label, $path);

        if (! $changed) {
            return LinkFixResult::skipped("Already links to “{$label}”.");
        }

        $this->republish($page, $actorId);

        return LinkFixResult::applied("Linked “{$page->title}” → “{$label}” and re-published.");
    }

    private function fixDeadEnd(Site $site, Content $page, ?string $actorId): LinkFixResult
    {
        $target = $this->siloHub($site, $page) ?? $this->homePage($site);
        if ($target === null || (string) $target->id === (string) $page->id) {
            return LinkFixResult::skipped('No topic hub or home page to link to — add an outbound link by hand.');
        }

        if (! $this->injector->appendRelated($page, (string) $target->title, $this->path($target))) {
            return LinkFixResult::skipped("Already links to “{$target->title}”.");
        }

        $this->republish($page, $actorId);

        return LinkFixResult::applied("Linked “{$page->title}” → “{$target->title}” and re-published.");
    }

    private function fixOrphan(Site $site, Content $page, ?string $actorId): LinkFixResult
    {
        $source = $this->siloHub($site, $page) ?? $this->homePage($site);
        if ($source === null || (string) $source->id === (string) $page->id) {
            return LinkFixResult::skipped('No topic hub or home page to link from — add an inbound link by hand.');
        }

        if (! $this->injector->appendRelated($source, (string) $page->title, $this->path($page))) {
            return LinkFixResult::skipped("“{$source->title}” already links here.");
        }

        $this->republish($source, $actorId);

        return LinkFixResult::applied("Linked “{$source->title}” → “{$page->title}” and re-published.");
    }

    private function siloHub(Site $site, Content $page): ?Content
    {
        if ($page->silo_id === null) {
            return null;
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)
            ->where('page_type', PageType::Hub->value)->where('silo_id', $page->silo_id)
            ->whereKeyNot($page->id)->first();
    }

    private function homePage(Site $site): ?Content
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)
            ->where('page_type', PageType::Home->value)->first();
    }

    private function owned(Site $site, string $contentId): ?Content
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereKey($contentId)->first();
    }

    private function republish(Content $page, ?string $actorId): void
    {
        if ($page->wp_post_id !== null) {
            PublishContent::dispatch((string) $page->id, $actorId);
        }
    }

    private function path(Content $page): string
    {
        return '/'.ltrim((string) $page->slug, '/');
    }
}
