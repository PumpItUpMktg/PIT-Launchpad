<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Bulk preview-push — the internal-only "show the whole site with every section" pass. Runs the
 * per-page {@see PagePreviewService} across every `kind=page` in a site, so an operator can browse the
 * COMPLETE design (every data-gated section rendered as a labeled example placeholder) on the real
 * WordPress instance.
 *
 * It is internal-only by construction: each page is pushed as a WordPress DRAFT (status='draft'),
 * visible only to logged-in operators via the preview link and never to a visitor. Nothing goes live —
 * no published page is touched and no `Content.status` flips — so the example placeholders can never
 * reach the public site. Undrafted pages are skipped (nothing to preview yet).
 */
final class SitePreviewService
{
    public function __construct(private readonly PagePreviewService $pagePreview) {}

    /**
     * @return list<array{content_id: string, title: string, slug: string, result: PreviewResult}>
     */
    public function previewSite(Site $site): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->orderBy('page_type')
            ->orderBy('slug')
            ->get();

        $out = [];
        foreach ($pages as $page) {
            $title = trim((string) $page->title) !== '' ? (string) $page->title : (string) $page->slug;
            $out[] = [
                'content_id' => (string) $page->id,
                'title' => $title,
                'slug' => (string) $page->slug,
                'result' => $this->pagePreview->preview($page),
            ];
        }

        return $out;
    }
}
