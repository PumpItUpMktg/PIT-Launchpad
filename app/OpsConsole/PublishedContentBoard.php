<?php

namespace App\OpsConsole;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;

/**
 * The console's "what's live" read model: every PUBLISHED piece of content for a site — blog posts AND
 * the other site pages — in one place, kept deliberately apart from the ready-to-publish queue. Read-only;
 * it reflects the live WordPress state the platform recorded, it does not change anything.
 */
class PublishedContentBoard
{
    /**
     * Live content for a site, split into blog posts and site pages, newest first.
     *
     * @return array{posts: list<array<string, mixed>>, pages: list<array<string, mixed>>}
     */
    public function forSite(?string $siteId): array
    {
        if ($siteId === null) {
            return ['posts' => [], 'pages' => []];
        }

        $domain = Site::query()->find($siteId)?->domain_url;

        $live = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('status', ContentStatus::Published->value)
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->get();

        return [
            'posts' => $live->where('kind', ContentKind::Post)->map(fn (Content $c): array => $this->card($c, $domain))->values()->all(),
            'pages' => $live->where('kind', ContentKind::Page)->map(fn (Content $c): array => $this->card($c, $domain))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function card(Content $content, ?string $domain): array
    {
        return [
            'id' => (string) $content->id,
            'title' => (string) $content->title,
            'url' => PublicUrl::forContent($domain, $content),
            'page_type' => $content->page_type?->value,
            'published_at' => optional($content->published_at ?? $content->updated_at)->format('M j, Y'),
        ];
    }
}
