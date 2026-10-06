<?php

namespace App\Operator\Coverage;

use App\Activity\ActivityRecorder;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Enums\RedirectSource;
use App\Jobs\GeneratePage;
use App\Jobs\GeneratePost;
use App\Jobs\PublishRedirects;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\DeleteFromWordpress;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The rework decision for pages Google CRAWLED and declined (§ Indexing) — a rewrite is the lever only
 * once Google has actually read the page, and even then the CAUSE decides the move:
 *
 *  - duplicate  — a post that is a near-duplicate of a live post (the funnel's near-dup link, or the two
 *                 titles share most of their words): merge it INTO the stronger one (redirect + take-down).
 *                 Rewriting the same topic stays a duplicate.
 *  - off_topic  — a post routed to no silo, or ingested with a relevance score under the floor: drop it.
 *                 Polishing it dilutes the site's topical focus.
 *  - thin       — everything else: rework it with the index brief (regenerate, more specific, denser).
 *
 * TOWN PAGES ARE NEVER PRUNED. Coverage is the point of a town page, so a town page is always `thin` —
 * reworked, never dropped or merged — and its priority sections survive the redraft.
 */
final class ReworkCandidates
{
    public const THIN = 'thin';

    public const DUPLICATE = 'duplicate';

    public const OFF_TOPIC = 'off_topic';

    public function __construct(
        private readonly IndexWatchlist $watchlist,
        private readonly DeleteFromWordpress $takeDown,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @return array{rework_days: int, rows: list<array{content_id: string, title: string, kind: string, url: ?string, days_waiting: ?int, is_town: bool, is_post: bool, verdict: string, reason: string, duplicate_of: array{content_id: string, title: string}|null, brief: string, rework: array{brief: string, verdict: string, requested_at: string, applied_at: string|null}|null}>, by_verdict: array<string, int>}
     */
    public function for(Site $site): array
    {
        $reworkDays = self::reworkDays();
        $rows = array_values(array_filter(
            $this->watchlist->for($site)['rows'],
            fn (array $r): bool => $r['state'] !== 'indexed' && $r['verdict'] === IndexCoverageState::CrawledNotIndexed->value && ($r['days_waiting'] ?? 0) >= $reworkDays,
        ));
        $pages = Content::withoutGlobalScope(SiteScope::class)->whereKey(array_column($rows, 'content_id'))->get()->keyBy('id');
        $livePosts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'slug', 'published_at']);

        $out = [];
        $byVerdict = [];
        foreach ($rows as $row) {
            $page = $pages->get($row['content_id']);
            if ($page === null) {
                continue;
            }
            $decision = $this->decide($page, $livePosts);
            $out[] = [
                'content_id' => $row['content_id'],
                'title' => $row['title'],
                'kind' => $row['kind'],
                'url' => $row['url'],
                'days_waiting' => $row['days_waiting'],
                'is_town' => self::isTown($page),
                'is_post' => $page->kind === ContentKind::Post,
                'verdict' => $decision['verdict'],
                'reason' => $decision['reason'],
                'duplicate_of' => $decision['duplicate_of'],
                'brief' => self::briefFor($page, $decision['verdict']),
                'rework' => IndexRework::on($page),
            ];
            $byVerdict[$decision['verdict']] = ($byVerdict[$decision['verdict']] ?? 0) + 1;
        }
        usort($out, fn (array $a, array $b): int => [$a['verdict'], -($a['days_waiting'] ?? 0)] <=> [$b['verdict'], -($b['days_waiting'] ?? 0)]);
        ksort($byVerdict);

        return ['rework_days' => $reworkDays, 'rows' => $out, 'by_verdict' => $byVerdict];
    }

    /**
     * The three-way check for one page.
     *
     * @param  Collection<int, Content>  $livePosts
     * @return array{verdict: string, reason: string, duplicate_of: array{content_id: string, title: string}|null}
     */
    public function decide(Content $page, $livePosts): array
    {
        if (self::isTown($page)) {
            return ['verdict' => self::THIN, 'reason' => 'A town page is reworked, never pruned — coverage is the point. Google read it and found too little that is true of this town.', 'duplicate_of' => null];
        }
        if ($page->kind !== ContentKind::Post) {
            return ['verdict' => self::THIN, 'reason' => 'Google read this page and declined it — it needs more specific, more useful material.', 'duplicate_of' => null];
        }

        // Duplicate: the funnel's near-dup link to a live post, else a title that shares most of its words with one.
        $dupOf = null;
        if ($page->near_dup_of_content_id !== null) {
            $twin = $livePosts->firstWhere('id', $page->near_dup_of_content_id);
            if ($twin !== null) {
                $dupOf = $twin;
            }
        }
        if ($dupOf === null) {
            $mine = self::tokens((string) $page->title);
            $best = null;
            $bestScore = 0.0;
            foreach ($livePosts as $other) {
                if ((string) $other->id === (string) $page->id || $mine === []) {
                    continue;
                }
                $theirs = self::tokens((string) $other->title);
                if ($theirs === []) {
                    continue;
                }
                $score = count(array_intersect($mine, $theirs)) / max(1, min(count($mine), count($theirs)));
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $other;
                }
            }
            if ($best !== null && $bestScore >= self::duplicateOverlap()) {
                $dupOf = $best;
            }
        }
        if ($dupOf !== null) {
            return ['verdict' => self::DUPLICATE, 'reason' => 'Near-duplicate of “'.$dupOf->title.'” — a rewrite of the same topic stays a duplicate; merge it into the stronger post.', 'duplicate_of' => ['content_id' => (string) $dupOf->id, 'title' => (string) $dupOf->title]];
        }

        $siloId = $page->matched_silo_id ?? $page->silo_id;
        $relevance = $page->relevance_score !== null ? (float) $page->relevance_score : null;
        if ($siloId === null || ($relevance !== null && $relevance < self::offTopicRelevance())) {
            return ['verdict' => self::OFF_TOPIC, 'reason' => $siloId === null ? 'Routed to no silo — off the site\'s topics. Polishing it dilutes topical focus; drop it.' : sprintf('Ingested with relevance %.2f, under the %.2f floor — off-topic for the site; drop it.', $relevance, self::offTopicRelevance()), 'duplicate_of' => null];
        }

        return ['verdict' => self::THIN, 'reason' => 'On topic and not a duplicate — Google read it and found it thin. Rework it with the index brief.', 'duplicate_of' => null];
    }

    /**
     * Queue the rework: store the brief and regenerate (a page through the page flow, a post through the
     * post flow). Only `thin` verdicts are reworked — a duplicate is merged and an off-topic post dropped by
     * the operator's explicit choice, never here.
     *
     * @param  list<string>  $contentIds
     * @return array{queued: int, skipped: list<string>}
     */
    public function apply(Site $site, array $contentIds, ?string $actorId = null): array
    {
        $report = $this->for($site);
        $rows = collect($report['rows'])->keyBy('content_id');
        $queued = 0;
        $skipped = [];
        foreach ($contentIds as $id) {
            $row = $rows->get($id);
            $page = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($id)->first();
            if ($row === null || $page === null || $row['verdict'] !== self::THIN) {
                $skipped[] = $id;

                continue;
            }
            IndexRework::request($page, self::THIN, $row['brief']);
            if ($page->kind === ContentKind::Post) {
                GeneratePost::enqueue($page, actorId: $actorId);
            } else {
                GeneratePage::enqueue($page, actorId: $actorId);
            }
            $queued++;
        }
        if ($queued > 0) {
            $this->activity->record((string) $site->id, ActivityRecorder::REPUSH, sprintf('Rework queued for %d crawled-not-indexed %s', $queued, $queued === 1 ? 'page' : 'pages'), ['pages' => $queued], null, $actorId, clientVisible: true);
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Merge a duplicate POST into the post it duplicates: a 301 from its URL to the stronger one (published
     * through the redirects job) and a take-down. Never a page.
     *
     * @return array{redirected: bool, deleted: bool, message: string}
     */
    public function merge(Content $duplicate, Content $into, ?string $actorId = null): array
    {
        if ($duplicate->kind !== ContentKind::Post || self::isTown($duplicate)) {
            throw new InvalidArgumentException('Only a blog post can be merged — a page (and every town page) is reworked, never removed.');
        }
        if ((string) $duplicate->site_id !== (string) $into->site_id || (string) $duplicate->id === (string) $into->id) {
            throw new InvalidArgumentException('Merge target must be another post on the same site.');
        }
        $from = '/'.trim((string) $duplicate->slug, '/').'/';
        $to = '/'.trim((string) $into->slug, '/').'/';
        Redirect::query()->updateOrCreate(
            ['site_id' => $duplicate->site_id, 'from_url' => $from],
            ['to_url' => $to, 'code' => 301, 'source' => RedirectSource::Duplicate, 'status' => 'active'],
        );
        PublishRedirects::dispatch((string) $duplicate->site_id);
        $result = $this->takeDown->delete($duplicate);
        $this->activity->record((string) $duplicate->site_id, ActivityRecorder::CONTENT_REJECTED, 'Merged “'.$duplicate->title.'” into “'.$into->title.'” (301)', [], (string) $duplicate->title, $actorId);

        return ['redirected' => true, 'deleted' => (bool) $result['deleted'] || ! $result['on_wp'], 'message' => (string) $result['message']];
    }

    public static function isTown(Content $page): bool
    {
        return $page->kind === ContentKind::Page && $page->page_type === PageType::Location;
    }

    /** The brief the drafter gets for a rework — what to change, in the operator's words. */
    public static function briefFor(Content $page, string $verdict): string
    {
        if (self::isTown($page)) {
            return 'Rework this town page so every section says something true of this town specifically: its own housing, flood, '
                .'elevation and soil facts, the real jobs and reviews nearby, and what the service means for homes here. Remove '
                .'any sentence that would read the same on a neighbouring town\'s page.';
        }
        if ($page->kind === ContentKind::Post) {
            return 'Rework this article to be the most specific, useful page on its subject for this region: concrete steps, real '
                .'numbers and conditions from the grounding, the local angle, and a clear answer to the question in the title. '
                .'Cut generic filler.';
        }

        return 'Rework this page to be substantially more specific and useful: the real scope of the service, concrete process and '
            .'cost drivers from the grounding, proof and local conditions where given. Cut generic filler.';
    }

    /** @return list<string> */
    private static function tokens(string $title): array
    {
        $stop = ['the', 'and', 'for', 'your', 'you', 'with', 'from', 'that', 'this', 'how', 'what', 'why', 'when', 'into', 'out', 'not', 'are', 'can', 'should', 'does', 'guide', 'homeowners', 'homeowner'];
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($title)) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w): bool => mb_strlen($w) > 2 && ! in_array($w, $stop, true))));
    }

    public static function reworkDays(): int
    {
        return max(1, (int) config('launchpad.indexing.rework_days', 30));
    }

    public static function duplicateOverlap(): float
    {
        return (float) config('launchpad.indexing.duplicate_overlap', 0.6);
    }

    public static function offTopicRelevance(): float
    {
        return (float) config('launchpad.indexing.off_topic_relevance', 0.4);
    }
}
