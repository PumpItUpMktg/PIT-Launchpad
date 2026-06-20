<?php

namespace App\Guided;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SpokeStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Spoke;

/**
 * The Grow dashboard read model (scaffold). Reads what's reliably present today — published /
 * in-flight Content (build stats) and recent reactive posts (the fresh-content feed). The town
 * queue waits on the coverage layer + the drip controller (both deferred), so it surfaces the
 * readiness data that exists and degrades to an empty state otherwise.
 */
class GrowDashboard
{
    /**
     * @return array{live: int, building: int, planned: int}
     */
    public function stats(Site $site): array
    {
        $content = fn () => Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);

        return [
            'live' => (clone $content())->where('status', ContentStatus::Published->value)->count(),
            'building' => (clone $content())->whereIn('status', [
                ContentStatus::Approved->value, ContentStatus::Rendering->value, ContentStatus::Publishing->value,
            ])->count(),
            'planned' => Spoke::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->whereIn('status', [SpokeStatus::Offered->value, SpokeStatus::Future->value, SpokeStatus::Content->value])
                ->count(),
        ];
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
