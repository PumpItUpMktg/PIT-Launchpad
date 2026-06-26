<?php

namespace App\Guided;

use App\ContentEngine\Drafting\GroundingReadiness;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Grow dashboard read model. The Active landing's primary content is the pages workbench
 * ({@see pages()}) — every planned `kind=page` Content row (materialized by §Build's PageMaterializer)
 * with its build-out state and the morphing primary action that makes generate → approve → publish
 * reachable. The header strip (live / building / planned) and the list are both derived from the
 * SAME page set so they never drift. The town queue + fresh-content feed are deferred-layer
 * scaffolds (coverage/drip + news engine) shown as clearly-labeled "activates later" sections.
 */
class GrowDashboard
{
    public function __construct(private readonly GroundingReadiness $grounding) {}

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
     * The pages workbench — one row per planned `kind=page` Content, most-actionable first, each
     * carrying its build-out state badge and the morphing primary (`generate` until drafted,
     * `review` while a draft awaits acceptance, `publish` once approved, `view` once live; `pending`
     * when the page has no kit yet so the composer can't run). The bulk Approve/Publish lanes act on
     * the `review`/`publish` rows respectively (never bulk-generate — generation is a deliberate
     * per-page action).
     *
     * @return list<array{id: string, title: string, permalink: string, state: string, tone: string, action: ?string, live_url: ?string, bulk: ?string}>
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
     * @return list<array{key: string, label: string, count: int, pages: list<array{id: string, title: string, permalink: string, state: string, tone: string, action: ?string, live_url: ?string, bulk: ?string}>}>
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
     * @return array{id: string, title: string, permalink: string, state: string, tone: string, action: ?string, live_url: ?string, bulk: ?string, rank: int, section: string}
     */
    private function row(Content $c): array
    {
        // The pre-generation gate: a planned page needs BOTH a composer (kit) AND resolvable
        // grounding to generate for real — kit but no grounding → "grounding pending"; no kit →
        // "composer pending". Neither lies about producing an empty draft.
        $primable = match (true) {
            $c->wireframe_kit_id === null => 'pending',          // composer pending
            ! $this->grounding->ready($c) => 'grounding',        // grounding pending
            default => 'generate',
        };

        [$action, $rank] = match (true) {
            $c->isGenerating() => [null, 3],
            ! $c->hasDraft() => [$primable, 2], // awaiting OR failed → (re)generate / pending
            $c->status === ContentStatus::Published => ['view', 5],
            $c->status === ContentStatus::Approved => ['publish', 1],
            default => ['review', 0], // a draft awaiting acceptance — most urgent
        };

        $state = $action === 'grounding' ? 'Grounding pending' : $c->buildStateLabel();

        return [
            'id' => (string) $c->id,
            'title' => $this->title($c),
            'permalink' => '/'.ltrim((string) $c->slug, '/'),
            'state' => $state,
            'tone' => $this->tone($state),
            'action' => $action,
            'live_url' => $action === 'view' ? $this->liveUrl($c) : null,
            // which bulk lane this row belongs to (the checkbox targets); generate is per-page only
            'bulk' => match ($action) {
                'review' => 'approve',
                'publish' => 'publish',
                default => null,
            },
            'rank' => $rank,
            'section' => $this->section($c->page_type),
        ];
    }

    private function tone(string $label): string
    {
        return match ($label) {
            'Published', 'Approved · ready to publish' => 'ok',
            'Draft ready for review' => 'warn',
            'Generating…', 'Publishing…' => 'info',
            'Generation failed', 'Publish failed' => 'danger',
            default => 'idle', // Ready to generate
        };
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

    /**
     * Recent reactive (news-driven) posts, drafted into the categories.
     *
     * @return list<array{title: string, status: string, silo: string}>
     */
    public function news(Site $site, int $limit = 6): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->with('matchedSilo')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Content $c) => [
                'title' => $this->title($c),
                'status' => ucfirst(str_replace('_', ' ', $c->status->value)),
                'silo' => $c->matchedSilo instanceof Silo ? (string) $c->matchedSilo->name : '',
            ])
            ->all();
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
