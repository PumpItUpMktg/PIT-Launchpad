<?php

namespace App\Metrics\Milestones;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\ClientMilestone;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Derives the client's narrative milestones (§ Client Dashboard v1, PR 5) from the metric spine + durable
 * page-index state — never hand-entered. Each milestone is upserted idempotently on (site, key), and only
 * when it has actually been reached, so an unmet milestone leaves no row.
 *
 * The dashboard reads these as the story beats ("Google indexed your first page on …", "10 posts live",
 * "first page-1 keyword"). Dates come straight from the spine's history, so they're honest and stable.
 */
class MilestoneDeriver
{
    /** Blog-volume thresholds that each earn a milestone as they're crossed. */
    private const BLOG_THRESHOLDS = [10, 50, 100];

    /**
     * Derive every milestone for a site. Returns the keys that now have a row (created or refreshed).
     *
     * @return list<string>
     */
    public function derive(Site $site): array
    {
        $derived = [];

        // First time the site earned any Search impression / click (GSC site totals).
        $derived[] = $this->firstSiteMetric($site, 'gsc', 'impressions', 'first_impression');
        $derived[] = $this->firstSiteMetric($site, 'gsc', 'clicks', 'first_click');

        // First day Google had at least one page indexed (durable index spine).
        $derived[] = $this->firstSiteMetric($site, 'index', 'pages_indexed', 'first_page_indexed');

        // First time any keyword reached page one (organic rank ≤ 10).
        $derived[] = $this->firstTop10Keyword($site);

        // Blog-volume beats.
        foreach ($this->blogMilestones($site) as $key) {
            $derived[] = $key;
        }

        return array_values(array_filter($derived));
    }

    /**
     * The earliest day a site-level metric was positive → its milestone. Null (no row) if never positive.
     */
    private function firstSiteMetric(Site $site, string $provider, string $metricKey, string $milestoneKey): ?string
    {
        $row = DB::table('metric_snapshots')
            ->where('site_id', $site->id)
            ->where('provider', $provider)
            ->where('metric_key', $metricKey)
            ->where('dimension_type', 'site')
            ->where('value_numeric', '>', 0)
            ->orderBy('period_date')
            ->first(['period_date', 'value_numeric']);

        if ($row === null) {
            return null;
        }

        return $this->upsert($site, $milestoneKey, substr((string) $row->period_date, 0, 10), [
            'value' => (int) round((float) $row->value_numeric),
        ]);
    }

    /** The earliest day any keyword reached organic rank ≤ 10. Null if none has. */
    private function firstTop10Keyword(Site $site): ?string
    {
        $row = DB::table('metric_snapshots')
            ->where('site_id', $site->id)
            ->where('provider', 'dataforseo')
            ->where('metric_key', 'rank')
            ->where('dimension_type', 'keyword')
            ->where('value_numeric', '<=', 10)
            ->orderBy('period_date')
            ->orderBy('value_numeric')
            ->first(['period_date', 'dimension_value', 'value_numeric', 'value_json']);

        if ($row === null) {
            return null;
        }

        $query = null;
        if (is_string($row->value_json) && $row->value_json !== '') {
            $decoded = json_decode($row->value_json, true);
            $query = is_array($decoded) && isset($decoded['query']) ? (string) $decoded['query'] : null;
        }

        return $this->upsert($site, 'first_top10_keyword', substr((string) $row->period_date, 0, 10), [
            'keyword_id' => (string) $row->dimension_value,
            'query' => $query,
            'rank' => (int) round((float) $row->value_numeric),
        ]);
    }

    /**
     * Blog-volume milestones: as the Nth post goes live, its publish date is the milestone.
     *
     * @return list<string>
     */
    private function blogMilestones(Site $site): array
    {
        $dates = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('published_at')
            ->orderBy('published_at')
            ->pluck('published_at')
            ->values();

        $keys = [];
        foreach (self::BLOG_THRESHOLDS as $n) {
            if ($dates->count() < $n) {
                continue;
            }
            $date = $dates->get($n - 1); // the Nth post's publish date
            $keys[] = $this->upsert($site, "blog_post_{$n}", $date->toDateString(), ['count' => $n]);
        }

        return $keys;
    }

    /**
     * Idempotent upsert of one milestone. occurred_on is historical/stable, so a re-derive is a no-op change.
     *
     * @param  array<string, mixed>  $payload
     */
    private function upsert(Site $site, string $key, string $occurredOn, array $payload): string
    {
        ClientMilestone::withoutGlobalScopes()->updateOrCreate(
            ['site_id' => $site->id, 'key' => $key],
            ['occurred_on' => $occurredOn, 'payload' => $payload, 'is_client_visible' => true],
        );

        return $key;
    }
}
