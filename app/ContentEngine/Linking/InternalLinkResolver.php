<?php

namespace App\ContentEngine\Linking;

use App\Build\Permalinks;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;

/**
 * Resolves the internal-link targets a locally-relevant blog post should point at:
 *
 *  - **Location pages** — one link per town the drafter actually named, but ONLY when a real,
 *    LIVE location page exists for that town (published + slug). This is the geographic-juice
 *    link the mesh is missing: a Trooper news post → the Trooper page. Matching is by town name,
 *    so a town's own page wins naturally; the brick/mortar hub only matches when the physical
 *    office IS that town (in which case linking the hub is exactly right). Never links a
 *    materialized-but-unpublished page — that would 404 on the live site.
 *  - **Silo pillar** — the topical-juice link: the service pillar the post is routed to, kept so
 *    the post reinforces its silo as well as its town (the two axes: service × location).
 *
 * Pure geographic pages only (`page_type=Location`, no `primary_service_id`) are location targets,
 * so a "Sump Pumps in Trooper" service page is never mistaken for the Trooper location page.
 */
class InternalLinkResolver
{
    public function __construct(private readonly Permalinks $permalinks = new Permalinks) {}

    /**
     * Town-as-named-in-the-draft → its live location-page path. Only towns with a published page
     * appear; the rest fall through to today's name-only behavior.
     *
     * @param  list<string>  $townNames  the towns the drafter reported using (Content.meta.towns)
     * @return array<string, string> townName => path
     */
    public function locationLinks(string $siteId, array $townNames): array
    {
        $names = array_values(array_filter(array_map('trim', $townNames), fn ($n) => $n !== ''));
        if ($names === []) {
            return [];
        }

        // Every live, linkable location page for the site, keyed by its town key. Built once, then
        // matched against each drafted town name in memory (no per-town query).
        $pagesByKey = [];
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('primary_service_id')
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereNotNull('slug')
            ->orderBy('title')
            ->get();

        foreach ($pages as $page) {
            $key = $this->townKey((string) $page->title);
            if ($key !== '' && ! isset($pagesByKey[$key])) {
                $pagesByKey[$key] = $this->permalinks->path($page);
            }
        }

        $links = [];
        foreach ($names as $name) {
            $path = $pagesByKey[$this->townKey($name)] ?? null;
            if ($path !== null) {
                $links[$name] = $path;
            }
        }

        return $links;
    }

    /**
     * The post's silo pillar as a link target — label + live path — or null when the silo has no
     * published pillar page yet (then the topical link is simply skipped, never a dead link).
     *
     * @return array{label: string, path: string}|null
     */
    public function siloPillarLink(?string $siloId): ?array
    {
        if ($siloId === null) {
            return null;
        }

        $silo = Silo::withoutGlobalScope(SiteScope::class)->find($siloId);
        if ($silo === null || $silo->pillar_content_id === null) {
            return null;
        }

        $pillar = Content::withoutGlobalScope(SiteScope::class)
            ->where('id', $silo->pillar_content_id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereNotNull('slug')
            ->first();

        if ($pillar === null) {
            return null;
        }

        $title = trim((string) $pillar->title);
        $label = $title !== '' ? $title : trim((string) $silo->name);

        return $label === '' ? null : ['label' => $label, 'path' => $this->permalinks->path($pillar)];
    }

    /**
     * Normalize a town name to a match key: the part before the first comma ("Trooper, PA" →
     * "trooper"), lowercased, punctuation stripped, spaces collapsed — so a drafted "Trooper"
     * matches a page titled "Trooper, PA" and vice versa without false cross-town hits.
     */
    private function townKey(string $value): string
    {
        $head = trim(explode(',', $value, 2)[0]);
        $head = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($head)) ?? '';

        return trim(preg_replace('/\s+/', ' ', $head) ?? '');
    }
}
