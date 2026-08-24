<?php

namespace App\Geo;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
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
            ->geoLane()
            ->where('status', ContentStatus::Published->value)
            ->with(['silo:id,name', 'site:id,brand_name'])
            ->get(['id', 'site_id', 'silo_id']);

        return $rows
            ->groupBy(fn (Content $c): string => (string) ($c->silo_id ?? '__none'))
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'silo_id' => $first->silo_id !== null ? (string) $first->silo_id : null,
                    'silo' => $first->silo?->name ?? 'Uncategorized',
                    'tenant' => $first->site?->brand_name,
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
            ->geoLane()
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
