<?php

namespace App\Geo;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * The AI-search content tally — the "body of work" view for GEO-lane content. Counts published GEO
 * posts per silo (portfolio-wide, operator context) so the AI Content page can show what the growth
 * loop has actually shipped, plus a small pipeline roll-up (candidates / in review / published). Pure
 * read-model over the persisted rows; no side effects.
 */
class GeoContentSummary
{
    /**
     * Published GEO content grouped by silo, most-published first. An unrouted post (no silo) rolls up
     * under "Uncategorized".
     *
     * @return list<array{silo_id: ?string, silo: string, tenant: ?string, published: int}>
     */
    public function publishedBySilo(): array
    {
        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('draft_lane', Content::GEO_LANE)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'silo_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        // Name lookups keyed by id — avoids per-row relation loads (and their nullable-relation typing).
        $siloNames = Silo::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $rows->pluck('silo_id')->filter()->unique()->all())
            ->pluck('name', 'id');
        $siteNames = Site::query()
            ->whereIn('id', $rows->pluck('site_id')->unique()->all())
            ->pluck('brand_name', 'id');

        return $rows
            ->groupBy(fn (Content $c): string => (string) ($c->silo_id ?? '__none'))
            ->map(function (Collection $group) use ($siloNames, $siteNames): array {
                $first = $group->first();
                $siloId = $first?->silo_id;
                $siteId = $first?->site_id;

                return [
                    'silo_id' => $siloId !== null ? (string) $siloId : null,
                    'silo' => $siloId !== null ? (string) ($siloNames[$siloId] ?? 'Uncategorized') : 'Uncategorized',
                    'tenant' => $siteId !== null ? ($siteNames[$siteId] ?? null) : null,
                    'published' => $group->count(),
                ];
            })
            ->sortByDesc('published')
            ->values()
            ->all();
    }

    /**
     * Pre-publish + published counts across the GEO lane — the top-of-page pipeline glance.
     *
     * @return array{candidates: int, in_review: int, published: int}
     */
    public function laneCounts(): array
    {
        $counts = Content::withoutGlobalScope(SiteScope::class)
            ->where('draft_lane', Content::GEO_LANE)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $sum = fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $s): int => (int) ($counts[$s] ?? 0));

        return [
            'candidates' => $sum([ContentStatus::Candidate->value, ContentStatus::Scored->value]),
            'in_review' => $sum([
                ContentStatus::NeedsReview->value,
                ContentStatus::InReview->value,
                ContentStatus::RenderFailed->value,
                ContentStatus::PublishFailed->value,
            ]),
            'published' => $sum([ContentStatus::Published->value]),
        ];
    }
}
