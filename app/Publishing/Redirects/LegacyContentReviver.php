<?php

namespace App\Publishing\Redirects;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\PublishContentService;
use Illuminate\Support\Str;

/**
 * Turns the legacy-redirect planner's high-value UNRESOLVED URLs — the
 * high-traffic informational pages the new site has no equivalent for — into
 * reviewable blog candidates instead of pillar redirects. Each candidate carries
 * its winning GSC query as the writing brief and remembers its source URL in
 * `meta.revived_from_url`, so when the operator generates + publishes it (the
 * normal gated flow — this NEVER drafts), {@see PublishContentService}
 * 301s the old URL to the new post. Equity moves to the specific successor, not a
 * generic pillar.
 *
 * Idempotent: a URL that already has a revived candidate (or now resolves to a
 * live page / redirect) is skipped, so re-running only fills gaps.
 */
class LegacyContentReviver
{
    public function __construct(private readonly LegacyRedirectPlanner $planner) {}

    /**
     * The revival candidates a run WOULD create (dry view): the unresolved URLs
     * at/above the impression floor, highest first, capped.
     *
     * @return list<array{from: string, query: ?string, impressions: int}>
     */
    public function plan(Site $site, ?int $minImpressions = null, ?int $limit = null): array
    {
        $floor = $minImpressions ?? (int) config('launchpad.legacy_revival.min_impressions', 5000);
        $cap = $limit ?? (int) config('launchpad.legacy_revival.limit', 100);

        $existing = $this->existingRevivedFrom($site);

        $rows = [];
        foreach ($this->planner->plan($site)['unresolved'] as $u) {
            if ($u['impressions'] < $floor || isset($existing[$u['from']])) {
                continue;
            }
            $rows[] = ['from' => $u['from'], 'query' => $u['top_query'], 'impressions' => $u['impressions']];
            if (count($rows) >= $cap) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Create a blog candidate per planned URL (status `candidate`, gated for
     * operator generation). Returns the created Content rows.
     *
     * @return list<Content>
     */
    public function revive(Site $site, ?int $minImpressions = null, ?int $limit = null): array
    {
        $created = [];
        foreach ($this->plan($site, $minImpressions, $limit) as $row) {
            $query = is_string($row['query']) && trim($row['query']) !== '' ? trim($row['query']) : $this->titleFromSlug($row['from']);
            $title = Str::title($query);

            $created[] = Content::create([
                'site_id' => $site->id,
                'kind' => ContentKind::Post,
                'intake_type' => IntakeType::Reactive,
                'draft_trigger' => DraftTrigger::OnDemand,
                'status' => ContentStatus::Candidate,
                'title' => $title,
                'slug' => $this->uniqueSlug($site->id, $title),
                'source_name' => 'Legacy revival (GSC)',
                'source_url' => $row['from'],
                'angle_hint' => sprintf(
                    'Revive a top-performing legacy article. Write a comprehensive, up-to-date post targeting the query “%s” (the old URL earned %s impressions for it). On publish, the old URL 301s to this post.',
                    $query,
                    number_format($row['impressions']),
                ),
                'version' => 1,
                'meta' => [
                    'revived_from_url' => $row['from'],
                    'revived_query' => $query,
                    'revived_impressions' => $row['impressions'],
                ],
            ]);
        }

        return $created;
    }

    /**
     * The set of legacy URLs already claimed by a revived candidate, so a re-run
     * never double-creates.
     *
     * @return array<string, bool>
     */
    private function existingRevivedFrom(Site $site): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('meta->revived_from_url')
            ->get(['meta'])
            ->map(fn (Content $c): mixed => $c->meta['revived_from_url'] ?? null)
            ->filter(fn ($v): bool => is_string($v) && $v !== '')
            ->mapWithKeys(fn ($v): array => [(string) $v => true])
            ->all();
    }

    private function titleFromSlug(string $from): string
    {
        $leaf = (string) Str::of($from)->trim('/')->afterLast('/')->replace('-', ' ');

        return $leaf === '' ? 'Legacy article' : $leaf;
    }

    private function uniqueSlug(string $siteId, string $title): string
    {
        $base = Str::slug($title) ?: 'legacy-post';
        $slug = $base;
        $n = 1;
        while (Content::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
