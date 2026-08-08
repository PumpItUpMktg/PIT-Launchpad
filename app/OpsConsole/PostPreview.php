<?php

namespace App\OpsConsole;

use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Str;

/**
 * The read-only pre-publish preview view model: everything that will hit WordPress, assembled from a
 * single {@see Content} so an operator can QA a post on the Approved page before releasing it to
 * Publish — the generate-time hero render, the drafted body, the engine-owned SEO, the internal
 * links the page makes and receives, and the towns it covers.
 *
 * It reads only what already exists on the row (no LLM, no render, no WordPress call) — a faithful
 * mirror of the drafted content, not a live WordPress render.
 */
final class PostPreview
{
    public function __construct(private readonly StorefrontTowns $towns) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Content $content): array
    {
        $seo = is_array($content->meta['seo'] ?? null) ? $content->meta['seo'] : [];
        $site = $content->site;

        return [
            'id' => (string) $content->id,
            'title' => (string) $content->title,
            'image' => PostThumbnail::for($content),
            'body' => (string) $content->body,
            'silo' => $content->matchedSilo?->name,
            'keyword' => $content->targetKeyword?->query,
            'tenant' => $site?->brand_name,
            'slug' => (string) $content->slug,
            'seo' => [
                'title' => trim((string) ($seo['title'] ?? '')) !== '' ? (string) $seo['title'] : (string) $content->title,
                'meta_description' => $seo['meta_description'] ?? null,
                'slug' => (string) $content->slug,
            ],
            'towns' => $this->coveredTowns($content),
            'outbound_links' => $this->outboundLinks((string) $content->body),
            'inbound_links' => $this->inboundLinks($content),
        ];
    }

    /** Towns this post covers (same match as the card enrichment). */
    private function coveredTowns(Content $content): array
    {
        $site = $content->site;
        if ($site === null) {
            return [];
        }

        return $this->towns->matchTowns($content, $this->towns->targetTowns($site, null, null));
    }

    /**
     * Internal links the page MAKES — anchors the drafter embedded in the body. Absolute/off-site
     * links are kept out (only relative or same-host hrefs count as internal).
     *
     * @return list<array{href: string, text: string}>
     */
    private function outboundLinks(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        if (! preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $body, $m, PREG_SET_ORDER)) {
            return [];
        }

        $links = [];
        foreach ($m as $match) {
            $href = trim($match[1]);
            $text = trim(strip_tags($match[2]));
            // External links (absolute URLs to another host) and empty anchors are not internal links.
            if ($href === '' || Str::startsWith($href, ['http://', 'https://', 'mailto:', 'tel:', '#'])) {
                continue;
            }
            $links[] = ['href' => $href, 'text' => $text !== '' ? $text : $href];
        }

        // Dedupe on href, preserve first anchor text.
        return collect($links)->unique('href')->values()->all();
    }

    /**
     * Internal links the page RECEIVES — other posts/pages on the same site whose body links to this
     * post's slug. Bounded (same-site, has-slug) and capped so the preview stays one cheap query.
     *
     * @return list<array{title: string, slug: string}>
     */
    private function inboundLinks(Content $content): array
    {
        $slug = trim((string) $content->slug, '/');
        if ($slug === '') {
            return [];
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('id', '!=', $content->id)
            ->whereNotNull('body')
            ->where('body', 'like', '%'.$slug.'%')
            ->limit(25)
            ->get(['id', 'title', 'slug'])
            ->map(fn (Content $c): array => ['title' => (string) $c->title, 'slug' => (string) $c->slug])
            ->all();
    }
}
