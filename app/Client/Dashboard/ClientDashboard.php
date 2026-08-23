<?php

namespace App\Client\Dashboard;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\JobStatus;
use App\Enums\PageType;
use App\Metrics\UrlNormalizer;
use App\Models\ClientMilestone;
use App\Models\Content;
use App\Models\Job;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\DB;

/**
 * The client dashboard read-model (§ Client Dashboard v1, PR 6a). Reads ONLY from the metric spine
 * (metric_snapshots + client_milestones) — never live from a provider — and returns plain arrays the
 * Filament surface (PR 6b) renders. Movement-first and honest by construction: it exposes counts, trends,
 * and observed milestones, and computes no ROI / attribution / causal field.
 *
 * Hero deltas are a fixed trailing-28-day momentum (recent 28 days vs the previous 28), independent of the
 * frame toggle, so "▲6 since last period" always means the same thing whichever frame is shown.
 */
class ClientDashboard
{
    private const MOMENTUM_DAYS = 28;

    /** @var array<string, array<string, true>> managed-path sets keyed by site id (per-request memo) */
    private array $managedPathsCache = [];

    /**
     * The three movement-first hero tiles.
     *
     * @return array{
     *   pages_working: array{value: int, delta: int},
     *   keywords_improved: array{value: int, reached_page1: int},
     *   pages_added: array{indexed: int, delta: int}
     * }
     */
    public function hero(Site $site, Frame $frame): array
    {
        return [
            'pages_working' => $this->pagesWorking($site, $frame),
            'keywords_improved' => $this->keywordsImproved($site, $frame),
            'pages_added' => $this->pagesAdded($site, $frame),
        ];
    }

    /**
     * Pages WE MANAGE that earn Search impressions within the frame, with trailing-28-day momentum. Scoped to
     * Launchpad-built pages (not the client's pre-existing site) so the dashboard never claims work we didn't
     * do — the whole-site footprint lives in the Search-visibility trend instead.
     */
    private function pagesWorking(Site $site, Frame $frame): array
    {
        $managed = $this->managedPaths($site);
        $working = fn (string $start, string $end): int => count(array_intersect_key(
            array_flip($this->gscImpressionPaths($site, $start, $end)),
            $managed,
        ));

        $end = $frame->end;
        $recent = $working($end->copy()->subDays(self::MOMENTUM_DAYS - 1)->toDateString(), $end->toDateString());
        $previous = $working($end->copy()->subDays(self::MOMENTUM_DAYS * 2 - 1)->toDateString(), $end->copy()->subDays(self::MOMENTUM_DAYS)->toDateString());

        return [
            'value' => $working($frame->startDate(), $frame->endDate()),
            'delta' => $recent - $previous,
        ];
    }

    /** Keywords whose organic rank improved over the frame, and how many reached page one. */
    private function keywordsImproved(Site $site, Frame $frame): array
    {
        $improved = 0;
        $page1 = 0;
        foreach ($this->keywordMovement($site, $frame) as $m) {
            if ($m['to'] < $m['baseline']) {
                $improved++;
            }
            // "Reached page one" = a genuine crossing INTO the top 10 during the frame — we must have seen it
            // off page one first, so a keyword that debuts already ranked isn't claimed as a reach.
            if ($m['to'] <= 10 && $m['baseline'] > 10) {
                $page1++;
            }
        }

        return ['value' => $improved, 'reached_page1' => $page1];
    }

    /**
     * Pages WE MANAGE that Google has indexed: a managed page counts as indexed if it earns GSC impressions
     * (can't without being indexed) OR URL Inspection reports PASS — the operator cards' union rule, scoped to
     * Launchpad-built pages. Deduped on the normalized path; no ratio. Delta = managed pages newly appearing
     * in Google over the last 28 days.
     */
    private function pagesAdded(Site $site, Frame $frame): array
    {
        $managed = $this->managedPaths($site);
        $end = $frame->end;
        $endDate = $frame->endDate();

        $indexed = [];
        foreach ($this->gscImpressionPaths($site, null, $endDate) as $path) {
            if (isset($managed[$path])) {
                $indexed[$path] = true;
            }
        }
        foreach ($this->indexPassUrls($site) as $url) {
            $path = UrlNormalizer::path($url);
            if (isset($managed[$path])) {
                $indexed[$path] = true;
            }
        }

        // Momentum: managed pages that began earning impressions in the last 28 days.
        $before = array_flip($this->gscImpressionPaths($site, null, $end->copy()->subDays(self::MOMENTUM_DAYS)->toDateString()));
        $newly = 0;
        foreach ($this->gscImpressionPaths($site, $end->copy()->subDays(self::MOMENTUM_DAYS - 1)->toDateString(), $endDate) as $path) {
            if (isset($managed[$path]) && ! isset($before[$path])) {
                $newly++;
            }
        }

        return ['indexed' => count($indexed), 'delta' => $newly];
    }

    /**
     * The set (path => true) of pages Launchpad manages for this site — published Content (pages + posts) and
     * published Jobs — normalized the same way GSC page keys are, so they intersect. Cached per instance.
     *
     * @return array<string, true>
     */
    private function managedPaths(Site $site): array
    {
        if (isset($this->managedPathsCache[$site->id])) {
            return $this->managedPathsCache[$site->id];
        }

        $paths = [];
        $contents = Content::withoutGlobalScopes()
            ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug')->get(['page_type', 'slug']);
        foreach ($contents as $content) {
            $url = PublicUrl::forContent($site->domain_url, $content);
            $paths[UrlNormalizer::path($url ?? '/'.ltrim((string) $content->slug, '/'))] = true;
        }

        $jobs = Job::withoutGlobalScopes()
            ->where('site_id', $site->id)->where('status', JobStatus::Published->value)->get();
        foreach ($jobs as $job) {
            $url = $job->publicUrl($site->domain_url);
            if ($url !== null) {
                $paths[UrlNormalizer::path($url)] = true;
            }
        }

        return $this->managedPathsCache[$site->id] = $paths;
    }

    /**
     * Distinct normalized page paths that earned GSC impressions on or before $end (optionally on/after $start).
     *
     * @return list<string>
     */
    private function gscImpressionPaths(Site $site, ?string $start, string $end): array
    {
        $q = DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', 'gsc')->where('metric_key', 'impressions')
            ->where('dimension_type', 'page')->where('value_numeric', '>', 0)
            ->where('period_date', '<=', $end);
        if ($start !== null) {
            $q->where('period_date', '>=', $start);
        }

        return $q->distinct()->pluck('dimension_value')->all();
    }

    /** URL-Inspection PASS page URLs for the site. @return list<string> */
    private function indexPassUrls(Site $site): array
    {
        return DB::table('page_index_states')
            ->where('site_id', $site->id)->where('index_verdict', 'PASS')
            ->pluck('url_normalized')->all();
    }

    /**
     * Impressions + clicks daily series over the frame, plus milestone markers falling inside it.
     *
     * @return array{series: list<array{date: string, impressions: int, clicks: int}>, markers: list<array{date: string, label: string}>}
     */
    public function visibility(Site $site, Frame $frame): array
    {
        $impr = $this->siteSeries($site, 'gsc', 'impressions', $frame);
        $clicks = $this->siteSeries($site, 'gsc', 'clicks', $frame);

        $series = [];
        foreach (array_keys($impr + $clicks) as $date) {
            $series[$date] = ['date' => $date, 'impressions' => (int) ($impr[$date] ?? 0), 'clicks' => (int) ($clicks[$date] ?? 0)];
        }
        ksort($series);

        $markers = [];
        foreach ($this->milestones($site) as $m) {
            if ($m['date'] >= $frame->startDate() && $m['date'] <= $frame->endDate()) {
                $markers[] = ['date' => $m['date'], 'label' => $m['label']];
            }
        }

        return ['series' => array_values($series), 'markers' => $markers];
    }

    /**
     * Keyword standings as of the frame end + the top movers.
     *
     * @return array{ranked: int, top3: int, top10: int, top20: int, movers: list<array{query: ?string, from: ?int, to: int, jump: int, new: bool}>}
     */
    public function standings(Site $site, Frame $frame): array
    {
        $end = $frame->endDate();
        $movers = [];
        foreach ($this->keywordMovement($site, $frame) as $m) {
            $isNew = $m['new'];
            $improved = $m['to'] < $m['baseline'];
            if (! $isNew && ! $improved) {
                continue;
            }
            $movers[] = [
                'query' => $m['query'],
                'from' => $isNew ? null : $m['baseline'],
                'to' => $m['to'],
                'jump' => $isNew ? 0 : $m['baseline'] - $m['to'],
                'new' => $isNew,
            ];
        }
        // Biggest climbers first; newly-ranked after (jump 0) but surfaced.
        usort($movers, fn (array $a, array $b): int => $b['jump'] <=> $a['jump']);

        return [
            'ranked' => $this->siteValueAsOf($site, 'dataforseo', 'keywords_ranked', $end),
            'top3' => $this->siteValueAsOf($site, 'dataforseo', 'keywords_top3', $end),
            'top10' => $this->siteValueAsOf($site, 'dataforseo', 'keywords_top10', $end),
            'top20' => $this->siteValueAsOf($site, 'dataforseo', 'keywords_top20', $end),
            'movers' => array_slice($movers, 0, 8),
        ];
    }

    /**
     * The client-visible milestones, most recent first.
     *
     * @return list<array{key: string, label: string, date: string, payload: array<string, mixed>}>
     */
    public function milestones(Site $site): array
    {
        return ClientMilestone::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('is_client_visible', true)
            ->orderByDesc('occurred_on')
            ->get()
            ->map(fn (ClientMilestone $m): array => [
                'key' => $m->key,
                'label' => $this->milestoneLabel($m),
                'date' => $m->occurred_on->toDateString(),
                'payload' => is_array($m->payload) ? $m->payload : [],
            ])
            ->all();
    }

    /**
     * Indexed vs total-known pages daily series over the frame.
     *
     * @return list<array{date: string, indexed: int, known: int}>
     */
    public function indexTrend(Site $site, Frame $frame): array
    {
        $indexed = $this->siteSeries($site, 'index', 'pages_indexed', $frame);
        $known = $this->siteSeries($site, 'index', 'pages_known', $frame);

        $out = [];
        foreach (array_keys($indexed + $known) as $date) {
            $out[$date] = ['date' => $date, 'indexed' => (int) ($indexed[$date] ?? 0), 'known' => (int) ($known[$date] ?? 0)];
        }
        ksort($out);

        return array_values($out);
    }

    /** @return array{launch_date: ?string, data_through: ?string} */
    public function meta(Site $site, Frame $frame): array
    {
        $through = DB::table('metric_snapshots')->where('site_id', $site->id)->max('period_date');

        return [
            'launch_date' => $frame->key === 'since_launch' ? $frame->startDate() : null,
            'data_through' => $through !== null ? substr((string) $through, 0, 10) : null,
        ];
    }

    /**
     * Managed published pages Google has NOT yet indexed, grouped by page type, oldest-waiting first — so the
     * client can see what's still pending and raise it if a page lingers. "Indexed" is the same honest union
     * the hero uses (earns GSC impressions OR URL-Inspection reports PASS). Correct non-index states — a 301
     * redirect source, a canonical duplicate — are excluded (they're working as intended, not pending). Read
     * model only; it takes no push action.
     *
     * @return array{total: int, groups: list<array{type: string, pages: list<array{title: string, url: ?string, published_on: ?string, days_waiting: ?int, status: string}>}>}
     */
    public function awaitingIndexing(Site $site): array
    {
        $impressions = array_flip($this->gscImpressionPaths($site, null, now()->toDateString()));
        $verdicts = DB::table('page_index_states')
            ->where('site_id', $site->id)
            ->pluck('index_verdict', 'url_normalized')
            ->all();

        // Non-index verdicts that are CORRECT, not pending — never shown as "awaiting".
        $correctlyExcluded = [IndexCoverageState::ExcludedRedirect->value, IndexCoverageState::ExcludedCanonical->value];

        $contents = Content::withoutGlobalScopes()
            ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug')->get(['kind', 'page_type', 'slug', 'title', 'published_at']);

        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];
        foreach ($contents as $c) {
            $url = PublicUrl::forContent($site->domain_url, $c);
            $path = UrlNormalizer::path($url ?? '/'.ltrim((string) $c->slug, '/'));
            if (isset($impressions[$path])) {
                continue; // earns Search impressions ⇒ Google holds it
            }

            $verdict = $url !== null ? ($verdicts[UrlNormalizer::url($url)] ?? null) : null;
            if ($verdict === 'PASS' || ($verdict !== null && in_array($verdict, $correctlyExcluded, true))) {
                continue;
            }

            $state = $verdict !== null ? IndexCoverageState::tryFrom($verdict) : null;
            $grouped[$this->typeBucket($c)][] = [
                'title' => (string) $c->title,
                'url' => $url,
                'published_on' => $c->published_at?->toDateString(),
                'days_waiting' => $c->published_at !== null ? (int) $c->published_at->diffInDays(now()) : null,
                'status' => ($state ?? IndexCoverageState::NotInspected)->label(),
            ];
        }

        $groups = [];
        $total = 0;
        foreach (['Service pages', 'Location pages', 'Core pages', 'Blog posts'] as $type) {
            if (empty($grouped[$type])) {
                continue;
            }
            usort($grouped[$type], fn (array $a, array $b): int => (string) ($a['published_on'] ?? '') <=> (string) ($b['published_on'] ?? ''));
            $groups[] = ['type' => $type, 'pages' => $grouped[$type]];
            $total += count($grouped[$type]);
        }

        return ['total' => $total, 'groups' => $groups];
    }

    /** The client-facing page-family bucket a page belongs to (mirrors the console's board grouping). */
    private function typeBucket(Content $c): string
    {
        if ($c->kind === ContentKind::Post) {
            return 'Blog posts';
        }

        return match ($c->page_type) {
            PageType::Service, PageType::Hub, PageType::Pillar, PageType::Cluster => 'Service pages',
            PageType::Location => 'Location pages',
            default => 'Core pages', // Home, Utility, or unset
        };
    }

    // ---- internals -------------------------------------------------------

    /**
     * Per-keyword movement over the frame: the baseline rank (last sample before the frame, else the first
     * sample inside it) vs the latest sample inside it. Keywords with no in-frame sample are skipped.
     *
     * @return list<array{keyword_id: string, query: ?string, baseline: int, to: int, new: bool}>
     */
    private function keywordMovement(Site $site, Frame $frame): array
    {
        $rows = DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', 'dataforseo')
            ->where('metric_key', 'rank')->where('dimension_type', 'keyword')
            ->where('period_date', '<=', $frame->endDate())
            ->orderBy('dimension_value')->orderBy('period_date')
            ->get(['dimension_value', 'period_date', 'value_numeric', 'value_json']);

        $byKeyword = [];
        foreach ($rows as $r) {
            $byKeyword[$r->dimension_value][] = $r;
        }

        $start = $frame->startDate();
        $end = $frame->endDate();
        $out = [];
        foreach ($byKeyword as $keywordId => $samples) {
            $before = null;
            $inFrame = [];
            foreach ($samples as $s) {
                $date = substr((string) $s->period_date, 0, 10);
                if ($date < $start) {
                    $before = (int) round((float) $s->value_numeric);
                } elseif ($date <= $end) {
                    $inFrame[] = $s;
                }
            }
            if ($inFrame === []) {
                continue;
            }
            $last = $inFrame[array_key_last($inFrame)];
            $to = (int) round((float) $last->value_numeric);
            $baseline = $before ?? (int) round((float) $inFrame[0]->value_numeric);

            $out[] = [
                'keyword_id' => (string) $keywordId,
                'query' => $this->queryOf($last->value_json),
                'baseline' => $baseline,
                'to' => $to,
                'new' => $before === null,
            ];
        }

        return $out;
    }

    private function queryOf(mixed $valueJson): ?string
    {
        if (! is_string($valueJson) || $valueJson === '') {
            return null;
        }
        $decoded = json_decode($valueJson, true);

        return is_array($decoded) && isset($decoded['query']) ? (string) $decoded['query'] : null;
    }

    /** A site-level daily series keyed date => value over the frame. @return array<string, float> */
    private function siteSeries(Site $site, string $provider, string $metricKey, Frame $frame): array
    {
        return DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', $provider)->where('metric_key', $metricKey)
            ->where('dimension_type', 'site')->where('period_grain', 'day')
            ->whereBetween('period_date', [$frame->startDate(), $frame->endDate()])
            ->orderBy('period_date')
            ->get(['period_date', 'value_numeric'])
            ->mapWithKeys(fn ($r): array => [substr((string) $r->period_date, 0, 10) => (float) $r->value_numeric])
            ->all();
    }

    /** The latest site-level value for a metric on or before $asOf (0 when none). */
    private function siteValueAsOf(Site $site, string $provider, string $metricKey, string $asOf): int
    {
        $row = DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', $provider)->where('metric_key', $metricKey)
            ->where('dimension_type', 'site')->where('period_date', '<=', $asOf)
            ->orderByDesc('period_date')
            ->first(['value_numeric']);

        return $row === null ? 0 : (int) round((float) $row->value_numeric);
    }

    private function milestoneLabel(ClientMilestone $m): string
    {
        $payload = is_array($m->payload) ? $m->payload : [];

        return match (true) {
            $m->key === 'first_page_indexed' => 'Google indexed your first page',
            $m->key === 'first_impression' => 'First appeared in Google Search',
            $m->key === 'first_click' => 'First clicks from Google Search',
            $m->key === 'first_top10_keyword' => 'First page-one keyword'.(isset($payload['query']) ? ': “'.$payload['query'].'”' : ''),
            str_starts_with($m->key, 'blog_post_') => ($payload['count'] ?? substr($m->key, 10)).' blog posts published',
            default => ucfirst(str_replace('_', ' ', $m->key)),
        };
    }
}
