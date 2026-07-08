<?php

namespace App\Guided;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Pages\Audience;
use App\Pages\PageState;
use App\Pages\PageStatePresenter;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Grow dashboard read model — the operator's action surface. Its primary content is the pages
 * workbench ({@see sections()}): every `kind=page` Content row grouped Core / Service / Town, each
 * carrying the canonical state vocabulary ({@see PageStatePresenter}) — the sacred client line, the
 * operator whose-move line, the append-only operator tail — plus the loop actions that make
 * generate → review → approve → publish reachable. The header counts derive from the same page set so
 * they never drift. All state words come from the one vocabulary source; none are spelled here.
 */
class GrowDashboard
{
    public function __construct(private readonly PageStatePresenter $presenter = new PageStatePresenter) {}

    /**
     * Build-out counts, page-based so the header strip agrees with the workbench list exactly:
     * `planned` = pages still awaiting generation (the [Generate] targets), `live` = published,
     * `building` = everything in motion between the two (generating / failed / draft-in-review /
     * approved / rendering / publishing).
     *
     * @return array{live: int, building: int, planned: int}
     */
    public function stats(Site $site): array
    {
        $pages = $this->pageContent($site);

        $live = $pages->filter(fn (Content $c) => $c->status === ContentStatus::Published)->count();
        $planned = $pages->filter(fn (Content $c) => $c->generationState() === 'awaiting')->count();

        return [
            'live' => $live,
            'planned' => $planned,
            'building' => $pages->count() - $live - $planned,
        ];
    }

    /**
     * The pages workbench — one row per `kind=page` Content, most-actionable first, each carrying the
     * canonical vocabulary (client line + operator whose-move + operator tail) and its loop actions.
     * The bulk Approve/Publish lanes act on the review/approved rows respectively (never bulk-generate
     * — generation is a deliberate per-page action).
     *
     * @return list<array{id: string, title: string, permalink: string, client_line: string, whose_move: string, operator_tail: ?string, tone: string, actions: list<string>, menu: list<string>, live_url: ?string, bulk: ?string}>
     */
    public function pages(Site $site): array
    {
        return $this->pageContent($site)
            ->map(fn (Content $c) => $this->row($c))
            ->sortBy('rank')
            ->values()
            ->map(fn (array $r): array => $this->present($r))
            ->all();
    }

    /**
     * The workbench grouped into its three readiness lanes — Core (home + standard pages), Service
     * (service / hub / pillar / cluster), Town (location). Flat-alphabetical hid that each type sits
     * in a different readiness state (core pages composer-pending, service pages generating, town
     * pages grounding-pending); sectioning makes that legible at a glance. Rows keep the
     * most-actionable-first rank WITHIN each section; empty sections are dropped, so a site with no
     * materialized town pages yet simply shows Core + Service.
     *
     * @return list<array{key: string, label: string, count: int, pages: list<array{id: string, title: string, permalink: string, client_line: string, whose_move: string, operator_tail: ?string, tone: string, actions: list<string>, menu: list<string>, live_url: ?string, bulk: ?string}>}>
     */
    public function sections(Site $site): array
    {
        $grouped = $this->pageContent($site)
            ->map(fn (Content $c) => $this->row($c))
            ->groupBy('section');

        $sections = [];
        foreach (self::SECTIONS as $key => $label) {
            $group = $grouped->get($key);
            if ($group === null || $group->isEmpty()) {
                continue;
            }

            $pages = $group->sortBy('rank')->values()
                ->map(fn (array $r): array => $this->present($r))
                ->all();

            $sections[] = ['key' => $key, 'label' => $label, 'count' => count($pages), 'pages' => $pages];
        }

        return $sections;
    }

    /** The ordered workbench lanes (Core → Service → Town), labels included. */
    private const SECTIONS = [
        'core' => 'Core pages',
        'service' => 'Service pages',
        'town' => 'Town pages',
    ];

    /** Strip the internal sort/group keys before a row reaches the view. */
    private function present(array $row): array
    {
        unset($row['rank'], $row['section']);

        return $row;
    }

    /**
     * Which workbench lane a page belongs to, from its page_type. Service/hub/pillar/cluster are the
     * targeting body of work; location is a town page; everything else (home, utility, untyped) is a
     * core page.
     */
    private function section(?PageType $type): string
    {
        return match ($type) {
            PageType::Location => 'town',
            PageType::Service, PageType::Hub, PageType::Pillar, PageType::Cluster => 'service',
            default => 'core', // home / utility / null
        };
    }

    /**
     * @return array{id: string, title: string, permalink: string, client_line: string, whose_move: string, operator_tail: ?string, tone: string, actions: list<string>, menu: list<string>, live_url: ?string, bulk: ?string, rank: int, section: string}
     */
    private function row(Content $c): array
    {
        $p = $this->presenter->present($c, Audience::Operator);
        $state = $p->state;

        // Operator-screen behavior derived from the canonical state. Review rows offer BOTH Review
        // (→ proof editor) and a per-page Approve; a failed row re-triggers the step that failed
        // (re-generate if it never drafted, re-publish if the push failed). Held / in-flight rows
        // carry no live action — their whose-move line says why.
        $actions = match ($state) {
            PageState::ReadyToGenerate => ['generate'],
            PageState::ReadyToReview => ['review', 'approve'],
            PageState::Approved => ['publish'],
            PageState::Live => ['view'],
            PageState::Failed => [$c->hasDraft() ? 'publish' : 'generate'],
            default => [], // Writing, Publishing, HeldComposer, HeldGrounding
        };

        // Secondary lifecycle controls, tucked in a per-row overflow menu so the primary stays clean.
        // Regenerate re-drafts anything that has (or expects) a draft; Reject sends a review draft back;
        // Lock protects a publishable page from being clobbered. In-flight rows (Writing / Publishing)
        // carry no menu — we never interrupt a running job.
        $menu = match ($state) {
            PageState::ReadyToReview => ['regenerate', 'reject'],
            PageState::Approved, PageState::Live => ['regenerate', 'lock'],
            PageState::Failed => ['regenerate'],
            default => [], // ReadyToGenerate / held / in-flight — nothing to re-draft or protect
        };

        // Take down — offered only when there is actually a live WordPress post to remove. It force-
        // deletes the post (freeing the slug) and keeps the plan row as republishable (§2's
        // DeleteFromWordpress), so the page can be regenerated or re-published on the same URL. Never
        // mid-job.
        $onWp = (int) ($c->wp_post_id ?? 0) > 0;
        if ($onWp && $state !== PageState::Writing && $state !== PageState::Publishing) {
            $menu[] = 'takedown';
        }

        // Most-actionable first within a lane: review → approved → (ready / held / failed) → in-flight → live.
        $rank = match ($state) {
            PageState::ReadyToReview => 0,
            PageState::Approved => 1,
            PageState::ReadyToGenerate, PageState::Failed, PageState::HeldComposer, PageState::HeldGrounding, PageState::HeldIntake => 2,
            PageState::Writing, PageState::Publishing => 3,
            PageState::Live => 5,
        };

        $bulk = match ($state) {
            PageState::ReadyToReview => 'approve',
            PageState::Approved => 'publish',
            default => null,
        };

        return [
            'id' => (string) $c->id,
            'title' => $this->title($c),
            'permalink' => '/'.ltrim((string) $c->slug, '/'),
            'client_line' => $p->clientLine,
            'whose_move' => $p->whoseMove,
            'operator_tail' => $p->operatorTail,
            'tone' => $p->tone,
            'actions' => $actions,
            'menu' => $menu,
            'live_url' => $state === PageState::Live ? $this->liveUrl($c) : null,
            'bulk' => $bulk,
            'rank' => $rank,
            'section' => $this->section($c->page_type),
        ];
    }

    private function liveUrl(Content $c): ?string
    {
        $domain = $c->site?->domain_url;
        if (! is_string($domain) || $domain === '') {
            return null;
        }

        return rtrim($domain, '/').'/'.ltrim((string) $c->slug, '/');
    }

    /** @return Collection<int, Content> */
    private function pageContent(Site $site): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->with('site')
            ->get();
    }

    private function title(Content $c): string
    {
        $title = $c->getAttribute('title');
        if (is_string($title) && trim($title) !== '') {
            return $title;
        }
        $metaTitle = data_get($c->meta, 'title');

        return is_string($metaTitle) && trim($metaTitle) !== '' ? $metaTitle : 'Untitled post';
    }
}
