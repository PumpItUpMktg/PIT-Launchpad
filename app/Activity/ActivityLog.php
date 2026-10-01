<?php

namespace App\Activity;

use App\Enums\AuditAction;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\ActivityEvent;
use App\Models\AuditLog;
use App\Models\CitationEvent;
use App\Models\CitationScanRun;
use App\Models\ClientMilestone;
use App\Models\Content;
use App\Models\Conversion;
use App\Models\GeoGridScan;
use App\Models\Job;
use App\Models\LaunchRun;
use App\Models\PageIndexState;
use App\Models\RefreshEvent;
use App\Models\ReviewImport;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use App\TownRank\TownVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one-page activity report (§ Activity): what the system completed for a site in a period and how its
 * numbers moved. DERIVED from the records the work itself writes — published pages and posts, refreshes,
 * Town Rank and GBP scans, citation runs, jobs, imports, launches, index verdicts, milestones, go-live —
 * plus the few operator actions {@see ActivityRecorder} logs because nothing else would. Every entry is a
 * plain sentence with a date; the headline strip counts the period; the movement block shows each metric
 * at the start and end of the period, observed only — no attribution or causal claim.
 *
 * `clientView` drops the operator-only entries (scans, imports, operator actions not marked visible), so
 * the same report serves the portal.
 */
final class ActivityLog
{
    public const CLIENT_KINDS = ['published', 'refreshed', 'indexed', 'milestone', 'live', 'jobs', 'sections', 'event'];

    public function __construct(private readonly TownVisibility $visibility) {}

    /**
     * @return array{
     *     period: array{key: string, label: string, start: string, end: string, days: int},
     *     headline: array<string, int>,
     *     timeline: list<array{date: string, entries: list<array{kind: string, summary: string, metrics: array<string, int|float|string>, client: bool}>}>,
     *     metrics: array<string, array{label: string, start: int|float|null, end: int|float|null, delta: int|float|null, kind: string}>,
     *     entries: int
     * }
     */
    public function for(Site $site, ActivityPeriod $period, bool $clientView = false): array
    {
        $entries = $this->entries($site, $period);
        if ($clientView) {
            $entries = array_values(array_filter($entries, fn (array $e): bool => $e['client']));
        }
        usort($entries, fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        $byDay = [];
        foreach ($entries as $e) {
            $byDay[$e['date']][] = ['kind' => $e['kind'], 'summary' => $e['summary'], 'metrics' => $e['metrics'], 'client' => $e['client']];
        }
        $timeline = [];
        foreach ($byDay as $date => $items) {
            $timeline[] = ['date' => $date, 'entries' => $items];
        }

        return [
            'period' => ['key' => $period->key, 'label' => $period->label, 'start' => $period->start->toDateString(), 'end' => $period->end->toDateString(), 'days' => $period->days()],
            'headline' => $this->headline($site, $period, $entries),
            'timeline' => $timeline,
            'metrics' => $this->metrics($site, $period),
            'entries' => count($entries),
        ];
    }

    /**
     * The headline strip: work done in the period, summed from the entries plus the leads total.
     *
     * @param  list<array{kind: string, date: string, summary: string, metrics: array<string, int|float|string>, client: bool}>  $entries
     * @return array<string, int>
     */
    private function headline(Site $site, ActivityPeriod $period, array $entries): array
    {
        $sum = fn (string $kind, string $key): int => (int) array_sum(array_map(
            fn (array $e): int => $e['kind'] === $kind ? (int) ($e['metrics'][$key] ?? 0) : 0,
            $entries,
        ));

        return [
            'pages_published' => $sum('published', 'pages'),
            'posts_published' => $sum('published', 'posts'),
            'pages_refreshed' => $sum('refreshed', 'pages'),
            'pages_indexed' => $sum('indexed', 'pages'),
            'town_scans' => $sum('scan', 'towns'),
            'gbp_scans' => $sum('gbp_scan', 'scans'),
            'citation_scans' => $sum('citations', 'locations'),
            'citations_found' => $sum('citations', 'found'),
            'jobs_captured' => $sum('jobs', 'jobs'),
            'sections_drafted' => $sum('sections', 'towns'),
            'leads' => (int) Conversion::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->whereBetween('occurred_at', [$period->start, $period->end])->sum('count'),
        ];
    }

    /**
     * Every dated entry in the period, one sentence each.
     *
     * @return list<array{kind: string, date: string, summary: string, metrics: array<string, int|float|string>, client: bool}>
     */
    private function entries(Site $site, ActivityPeriod $period): array
    {
        $out = [];
        $range = [$period->start, $period->end];
        $siteId = (string) $site->id;
        $day = fn ($ts): string => Carbon::parse((string) $ts)->toDateString();
        $plural = fn (int $n, string $one, ?string $many = null): string => $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));

        // Published pages and posts, by day and type.
        $published = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('status', ContentStatus::Published->value)
            ->whereBetween('published_at', $range)
            ->get(['kind', 'page_type', 'location_id', 'parent_location_id', 'published_at']);
        $byDay = [];
        foreach ($published as $c) {
            $d = $day($c->published_at);
            $type = $c->kind === ContentKind::Post ? 'posts'
                : ($c->page_type === PageType::Location ? ($c->parent_location_id !== null && $c->location_id === null ? 'town pages' : 'location pages')
                : ($c->page_type === PageType::Service ? 'service pages' : 'pages'));
            $byDay[$d][$type] = ($byDay[$d][$type] ?? 0) + 1;
        }
        foreach ($byDay as $d => $types) {
            $parts = [];
            $pages = 0;
            $posts = 0;
            foreach ($types as $type => $n) {
                $parts[] = $n.' '.($n === 1 ? rtrim($type, 's') : $type);
                if ($type === 'posts') {
                    $posts += $n;
                } else {
                    $pages += $n;
                }
            }
            $out[] = ['kind' => 'published', 'date' => $d, 'summary' => 'Published '.implode(', ', $parts), 'metrics' => ['pages' => $pages, 'posts' => $posts], 'client' => true];
        }

        // Refreshed pages (RefreshEvent rows).
        foreach ($this->countByDay(RefreshEvent::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereBetween('created_at', $range), 'created_at') as $d => $n) {
            $out[] = ['kind' => 'refreshed', 'date' => $d, 'summary' => 'Refreshed '.$plural($n, 'page'), 'metrics' => ['pages' => $n], 'client' => true];
        }

        // Pages Google indexed (first PASS).
        foreach ($this->countByDay(PageIndexState::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereBetween('indexed_at', $range), 'indexed_at') as $d => $n) {
            $out[] = ['kind' => 'indexed', 'date' => $d, 'summary' => 'Google indexed '.$plural($n, 'page'), 'metrics' => ['pages' => $n], 'client' => true];
        }

        // Town Rank scans: keywords × towns per day.
        $scans = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->whereIn('status', ['complete', 'partial'])->whereBetween('scanned_at', $range)
            ->get(['keyword_id', 'points_count', 'scanned_at']);
        $scanDays = [];
        foreach ($scans as $s) {
            $d = $day($s->scanned_at);
            $scanDays[$d]['keywords'][(string) $s->keyword_id] = true;
            $scanDays[$d]['towns'] = ($scanDays[$d]['towns'] ?? 0) + (int) $s->points_count;
            $scanDays[$d]['scans'] = ($scanDays[$d]['scans'] ?? 0) + 1;
        }
        foreach ($scanDays as $d => $v) {
            $k = count($v['keywords']);
            $out[] = ['kind' => 'scan', 'date' => $d, 'summary' => 'Town Rank: '.$plural($k, 'keyword').' scanned across '.number_format($v['towns']).' town searches', 'metrics' => ['keywords' => $k, 'towns' => $v['towns'], 'scans' => $v['scans']], 'client' => false];
        }

        // GBP map-pack (geo-grid) scans.
        $grid = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('status', 'complete')->whereBetween('scanned_at', $range)
            ->get(['keyword_id', 'location_id', 'scanned_at']);
        $gridDays = [];
        foreach ($grid as $g) {
            $d = $day($g->scanned_at);
            $gridDays[$d]['scans'] = ($gridDays[$d]['scans'] ?? 0) + 1;
            $gridDays[$d]['keywords'][(string) $g->keyword_id] = true;
            $gridDays[$d]['locations'][(string) $g->location_id] = true;
        }
        foreach ($gridDays as $d => $v) {
            $out[] = ['kind' => 'gbp_scan', 'date' => $d, 'summary' => 'GBP map pack: '.$plural($v['scans'], 'scan').' ('.$plural(count($v['keywords']), 'keyword').', '.$plural(count($v['locations']), 'location').')', 'metrics' => ['scans' => $v['scans']], 'client' => false];
        }

        // Citation scans and what they found / fixed.
        $runs = $this->countByDay(CitationScanRun::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereNull('error')->whereNotNull('finished_at')->whereBetween('finished_at', $range), 'finished_at');
        $found = $this->countByDay(CitationEvent::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereIn('event_type', ['discovered', 'verified'])->whereBetween('created_at', $range), 'created_at');
        $fixed = $this->countByDay(CitationEvent::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->where('event_type', 'fixed')->whereBetween('created_at', $range), 'created_at');
        foreach (array_unique(array_merge(array_keys($runs), array_keys($found), array_keys($fixed))) as $d) {
            $parts = [];
            if (($runs[$d] ?? 0) > 0) {
                $parts[] = $plural($runs[$d], 'location').' scanned';
            }
            if (($found[$d] ?? 0) > 0) {
                $parts[] = $plural($found[$d], 'listing').' found';
            }
            if (($fixed[$d] ?? 0) > 0) {
                $parts[] = $plural($fixed[$d], 'listing').' fixed';
            }
            $out[] = ['kind' => 'citations', 'date' => $d, 'summary' => 'Citations: '.implode(', ', $parts), 'metrics' => ['locations' => $runs[$d] ?? 0, 'found' => $found[$d] ?? 0, 'fixed' => $fixed[$d] ?? 0], 'client' => ($found[$d] ?? 0) + ($fixed[$d] ?? 0) > 0];
        }

        // Jobs captured in the field.
        foreach ($this->countByDay(Job::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereBetween('created_at', $range), 'created_at') as $d => $n) {
            $out[] = ['kind' => 'jobs', 'date' => $d, 'summary' => $plural($n, 'job').' captured', 'metrics' => ['jobs' => $n], 'client' => true];
        }

        // Review imports.
        foreach (ReviewImport::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereNull('error')->whereBetween('created_at', $range)->get(['imported_count', 'created_at']) as $imp) {
            if ((int) $imp->imported_count > 0) {
                $out[] = ['kind' => 'reviews', 'date' => $day($imp->created_at), 'summary' => 'Imported '.$plural((int) $imp->imported_count, 'review'), 'metrics' => ['reviews' => (int) $imp->imported_count], 'client' => false];
            }
        }

        // Launch runs.
        foreach (LaunchRun::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereNotNull('completed_at')->whereBetween('completed_at', $range)->get(['pushed', 'completed_at']) as $run) {
            $out[] = ['kind' => 'launch', 'date' => $day($run->completed_at), 'summary' => 'Launch run pushed '.$plural((int) $run->pushed, 'page'), 'metrics' => ['pages' => (int) $run->pushed], 'client' => false];
        }

        // Priority sections drafted (meta.priority_sections_at on town pages).
        $sections = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('page_type', PageType::Location->value)
            ->whereRaw('CAST(meta AS TEXT) LIKE ?', ['%priority_sections_at%'])
            ->get(['meta']);
        $sectionDays = [];
        foreach ($sections as $c) {
            $at = is_array($c->meta) ? ($c->meta['priority_sections_at'] ?? null) : null;
            if (! is_string($at)) {
                continue;
            }
            $t = Carbon::parse($at);
            if ($t->between($period->start, $period->end)) {
                $sectionDays[$t->toDateString()] = ($sectionDays[$t->toDateString()] ?? 0) + 1;
            }
        }
        foreach ($sectionDays as $d => $n) {
            $out[] = ['kind' => 'sections', 'date' => $d, 'summary' => 'Priority sections drafted for '.$plural($n, 'town'), 'metrics' => ['towns' => $n], 'client' => true];
        }

        // Milestones.
        foreach (ClientMilestone::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereBetween('occurred_on', [$period->start->toDateString(), $period->end->toDateString()])->get() as $m) {
            $out[] = ['kind' => 'milestone', 'date' => $m->occurred_on->toDateString(), 'summary' => self::milestoneLabel((string) $m->key, is_array($m->payload) ? $m->payload : []), 'metrics' => [], 'client' => (bool) $m->is_client_visible];
        }

        // Go-live (the audit row targets the site).
        foreach (AuditLog::query()->where('action', AuditAction::SiteWentLive->value)->where('target_id', $siteId)->whereBetween('created_at', $range)->get(['created_at']) as $row) {
            $out[] = ['kind' => 'live', 'date' => $day($row->created_at), 'summary' => 'Site went live', 'metrics' => [], 'client' => true];
        }

        // Recorded operator actions.
        foreach (ActivityEvent::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereBetween('occurred_at', $range)->get() as $ev) {
            $out[] = ['kind' => 'event', 'date' => $day($ev->occurred_at), 'summary' => (string) $ev->summary, 'metrics' => is_array($ev->metrics) ? $ev->metrics : [], 'client' => (bool) $ev->client_visible];
        }

        return $out;
    }

    /**
     * Metric movement over the period: each metric at the start and the end (as-of values), or summed
     * over the period against the one before it (flow values). Observed only.
     *
     * @return array<string, array{label: string, start: int|float|null, end: int|float|null, delta: int|float|null, kind: string}>
     */
    public function metrics(Site $site, ActivityPeriod $period): array
    {
        $before = $period->previousEnd()->toDateString();
        $end = $period->end->toDateString();
        $out = [];

        $out['pages_live'] = self::asOf('Pages live', $this->publishedAsOf($site, $before), $this->publishedAsOf($site, $end));
        $out['pages_indexed'] = self::asOf('Pages indexed', $this->spineAsOf($site, 'index', 'pages_indexed', $before), $this->spineAsOf($site, 'index', 'pages_indexed', $end));
        $out['keywords_top10'] = self::asOf('Page-1 keywords', $this->spineAsOf($site, null, 'keywords_top10', $before), $this->spineAsOf($site, null, 'keywords_top10', $end));
        $out['town_visibility'] = self::asOf('Town Visibility', $this->visibilityAsOf($site, $before), $this->visibilityAsOf($site, $end));
        $out['impressions'] = self::flow('Search impressions', $this->spineSum($site, 'gsc', 'impressions', $period->previousStart(), $period->previousEnd()), $this->spineSum($site, 'gsc', 'impressions', $period->start, $period->end));
        $out['clicks'] = self::flow('Search clicks', $this->spineSum($site, 'gsc', 'clicks', $period->previousStart(), $period->previousEnd()), $this->spineSum($site, 'gsc', 'clicks', $period->start, $period->end));
        $out['leads'] = self::flow('Leads', $this->leads($site, $period->previousStart(), $period->previousEnd()), $this->leads($site, $period->start, $period->end));

        return $out;
    }

    /** @return array{label: string, start: int|float|null, end: int|float|null, delta: int|float|null, kind: string} */
    private static function asOf(string $label, int|float|null $start, int|float|null $end): array
    {
        return ['label' => $label, 'start' => $start, 'end' => $end, 'delta' => $start !== null && $end !== null ? $end - $start : null, 'kind' => 'as_of'];
    }

    /** @return array{label: string, start: int|float|null, end: int|float|null, delta: int|float|null, kind: string} */
    private static function flow(string $label, int|float|null $previous, int|float|null $current): array
    {
        return ['label' => $label, 'start' => $previous, 'end' => $current, 'delta' => $previous !== null && $current !== null ? $current - $previous : null, 'kind' => 'flow'];
    }

    private function publishedAsOf(Site $site, string $date): int
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)
            ->whereNotNull('published_at')->where('published_at', '<=', $date.' 23:59:59')
            ->count();
    }

    /** The latest site-level spine value on or before the date; null when the metric has never been synced. */
    private function spineAsOf(Site $site, ?string $provider, string $metricKey, string $date): ?int
    {
        $q = DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('metric_key', $metricKey)
            ->where('dimension_type', 'site')->where('period_date', '<=', $date)
            ->orderByDesc('period_date');
        if ($provider !== null) {
            $q->where('provider', $provider);
        }
        $row = $q->first(['value_numeric']);

        return $row === null ? null : (int) round((float) $row->value_numeric);
    }

    /** A daily spine metric summed over a window; null when the metric has never been synced. */
    private function spineSum(Site $site, string $provider, string $metricKey, Carbon $from, Carbon $to): ?int
    {
        $q = DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', $provider)->where('metric_key', $metricKey)
            ->where('dimension_type', 'site')->where('period_grain', 'day')
            ->whereBetween('period_date', [$from->toDateString(), $to->toDateString()]);
        if (! DB::table('metric_snapshots')->where('site_id', $site->id)->where('provider', $provider)->where('metric_key', $metricKey)->where('dimension_type', 'site')->exists()) {
            return null;
        }

        return (int) round((float) $q->sum('value_numeric'));
    }

    private function leads(Site $site, Carbon $from, Carbon $to): ?int
    {
        if (! Conversion::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists()) {
            return null;
        }

        return (int) Conversion::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereBetween('occurred_at', [$from, $to])->sum('count');
    }

    /** Town Visibility (town search) as of a date: the latest scored scan date on or before it. */
    private function visibilityAsOf(Site $site, string $date): ?int
    {
        $history = $this->visibility->forSite($site)[TownRankScan::MODE_TOWN_QUERY]['history'] ?? [];
        $value = null;
        foreach ($history as $point) {
            if ($point['date'] <= $date) {
                $value = (int) $point['score'];
            }
        }

        return $value;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array<string, int> Y-m-d → count
     */
    private function countByDay($query, string $column): array
    {
        $out = [];
        foreach ($query->get([$column]) as $row) {
            $d = Carbon::parse((string) $row->{$column})->toDateString();
            $out[$d] = ($out[$d] ?? 0) + 1;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $payload */
    public static function milestoneLabel(string $key, array $payload): string
    {
        return match (true) {
            $key === 'first_page_indexed' => 'Milestone: Google indexed the first page',
            $key === 'first_impression' => 'Milestone: first appearance in Google Search',
            $key === 'first_click' => 'Milestone: first clicks from Google Search',
            $key === 'first_top10_keyword' => 'Milestone: first page-one keyword'.(isset($payload['query']) ? ' — “'.$payload['query'].'”' : ''),
            str_starts_with($key, 'blog_post_') => 'Milestone: '.($payload['count'] ?? substr($key, 10)).' blog posts published',
            default => 'Milestone: '.ucfirst(str_replace('_', ' ', $key)),
        };
    }
}
