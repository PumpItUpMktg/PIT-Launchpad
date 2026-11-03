<?php

namespace App\ContentEngine\Questions;

use App\ContentEngine\DuplicateGuard;
use App\Enums\CandidateScope;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Enums\ShelfLife;
use App\KeywordGenerator\Bucketer;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a mining report into the blog lane's work, two steps so the report-first run and the --execute
 * run share one decision: {@see plan()} decides per topic (verdict, silo, action) and writes nothing;
 * {@see apply()} performs the planned actions.
 *
 * Actions, in the order a topic is judged:
 *  - covered: a post of ours already ranks in the top slots — listed, nothing filed, not counted.
 *  - dismissed: an operator rejected this question's candidate before — never re-filed.
 *  - already: a candidate for it is on the board (or further along) — its demand numbers are refreshed,
 *    no second row. Counts toward the cap (it is the standing ask).
 *  - mark: the post that answers it gets a refresh mark (meta.question_refresh) — the operator sees it
 *    on the board; no second post is filed. Counts toward the cap.
 *  - unrouted: no silo's rule_set claims the question — listed for the operator, never guessed.
 *  - file: a new "questions" post candidate, pinned to its silo, the question as the angle. Counts.
 *
 * The cap (`top`) is spent by file / mark / already only — the standing asks on the board.
 *
 * Nothing drafts on its own: a filed candidate waits for Generate like any other.
 */
class QuestionIntake
{
    public const SOURCE = 'questions';

    public const ACTION_FILE = 'file';

    public const ACTION_MARK = 'mark';

    public const ACTION_COVERED = 'covered';

    public const ACTION_ALREADY = 'already';

    public const ACTION_DISMISSED = 'dismissed';

    public const ACTION_UNROUTED = 'unrouted';

    public function __construct(
        private readonly QuestionCoverage $coverage,
        private readonly Bucketer $bucketer,
        private readonly DuplicateGuard $guard,
    ) {}

    /**
     * @return list<array{cluster: QuestionCluster, verdict: QuestionVerdict, silo: Silo|null, action: string, existing: Content|null}>
     */
    public function plan(Site $site, QuestionMiningReport $report, ?int $top = null): array
    {
        $top = $top ?? (int) config('launchpad.questions.top', 15);
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $existing = $this->existingRows($site);

        $rows = [];
        $spent = 0;
        foreach ($report->clusters as $cluster) {
            if ($spent >= $top) {
                break;
            }
            $verdict = $this->coverage->verdict($site, $cluster);
            $row = ['cluster' => $cluster, 'verdict' => $verdict, 'silo' => null, 'action' => self::ACTION_COVERED, 'existing' => null];

            if ($verdict->isCovered()) {
                $rows[] = $row;

                continue;
            }

            $prior = $this->priorFor($existing, $cluster);
            if ($prior !== null && $prior->status === ContentStatus::Rejected) {
                $rows[] = [...$row, 'action' => self::ACTION_DISMISSED, 'existing' => $prior];

                continue;
            }

            if ($verdict->isRefresh()) {
                $rows[] = [...$row, 'action' => self::ACTION_MARK, 'existing' => $verdict->content];
                $spent++;

                continue;
            }

            if ($prior !== null) {
                $rows[] = [...$row, 'action' => self::ACTION_ALREADY, 'existing' => $prior];
                $spent++;

                continue;
            }

            $silo = $this->bucketer->bucket($cluster->query(), $silos);
            if ($silo === null) {
                $rows[] = [...$row, 'action' => self::ACTION_UNROUTED];

                continue;
            }

            $rows[] = [...$row, 'silo' => $silo, 'action' => self::ACTION_FILE];
            $spent++;
        }

        return $rows;
    }

    /**
     * Perform a plan's writes. Idempotent per topic: re-applying re-stamps numbers, never duplicates.
     *
     * @param  list<array{cluster: QuestionCluster, verdict: QuestionVerdict, silo: Silo|null, action: string, existing: Content|null}>  $plan
     * @return array{filed: int, marked: int, refreshed: int}
     */
    public function apply(Site $site, QuestionMiningReport $report, array $plan): array
    {
        $out = ['filed' => 0, 'marked' => 0, 'refreshed' => 0];
        $now = CarbonImmutable::now();

        foreach ($plan as $row) {
            $cluster = $row['cluster'];
            $verdict = $row['verdict'];
            $facts = $this->facts($cluster, $verdict, $report, $now);

            switch ($row['action']) {
                case self::ACTION_FILE:
                    /** @var Silo $silo */
                    $silo = $row['silo'];
                    $this->file($site, $cluster, $silo, $facts);
                    $out['filed']++;
                    break;

                case self::ACTION_MARK:
                    /** @var Content $post */
                    $post = $row['existing'];
                    $meta = is_array($post->meta) ? $post->meta : [];
                    $meta['question_refresh'] = [...$facts, 'reason' => $verdict->reason, 'similarity' => $verdict->similarity];
                    $post->forceFill(['meta' => $meta])->save();
                    $out['marked']++;
                    break;

                case self::ACTION_ALREADY:
                    /** @var Content $prior */
                    $prior = $row['existing'];
                    if (in_array($prior->status, [ContentStatus::Candidate, ContentStatus::Scored, ContentStatus::InReview], true)) {
                        $meta = is_array($prior->meta) ? $prior->meta : [];
                        $meta['question'] = [...$facts, 'verdict_reason' => $verdict->reason];
                        $prior->forceFill(['meta' => $meta])->save();
                        $out['refreshed']++;
                    }
                    break;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> the demand facts stored on a candidate or a mark */
    private function facts(QuestionCluster $cluster, QuestionVerdict $verdict, QuestionMiningReport $report, CarbonImmutable $now): array
    {
        return [
            'query' => $cluster->query(),
            'phrasings' => $cluster->otherPhrasings(),
            'impressions' => $cluster->impressions(),
            'clicks' => $cluster->clicks(),
            'position' => $verdict->position ?? $cluster->position(),
            'url' => $verdict->rankingUrl,
            'window_from' => $report->from,
            'window_to' => $report->to,
            'mined_at' => $now->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $facts */
    private function file(Site $site, QuestionCluster $cluster, Silo $silo, array $facts): Content
    {
        $title = self::titleFor($cluster->query());
        $dupOf = $this->guard->duplicateOf($site, $title, (string) $silo->id);

        $others = $cluster->otherPhrasings();
        $angle = sprintf('Answer the question searchers typed into Google: “%s”.', $cluster->query())
            .($others !== [] ? ' They also phrase it: '.implode(', ', array_map(fn (string $p): string => "“{$p}”", array_slice($others, 0, 4))).'.' : '')
            .' Lead with the direct answer, then the practical detail a homeowner needs to act on it.';

        return Content::create([
            'site_id' => $site->id,
            'silo_id' => $silo->id,
            'matched_silo_id' => $silo->id,
            'kind' => ContentKind::Post,
            'intake_type' => IntakeType::Directed,
            'draft_trigger' => DraftTrigger::Question,
            'status' => $dupOf !== null ? ContentStatus::InReview : ContentStatus::Candidate,
            'near_dup_of_content_id' => $dupOf,
            'source_name' => self::SOURCE,
            'title' => $title,
            'slug' => $this->uniqueSlug((string) $site->id, $title),
            'angle_hint' => $angle,
            'version' => 1,
            'meta' => [
                'shelf_life' => ShelfLife::Evergreen->value,
                'scope' => CandidateScope::General->value,
                'question' => $facts,
            ],
        ]);
    }

    /** "how long do sump pumps last" → "How long do sump pumps last?"; an intent query keeps its form. */
    public static function titleFor(string $query): string
    {
        $q = trim($query);
        $first = mb_strtolower((string) (preg_split('/\s+/', $q)[0] ?? ''));
        $isQuestion = in_array(rtrim($first, '?,.'), QuestionShape::QUESTION_WORDS, true);

        return Str::ucfirst(rtrim($q, '?.!')).($isQuestion ? '?' : '');
    }

    /** @return Collection<int, Content> every questions-lane row of the site (any status), newest first */
    private function existingRows(Site $site): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('source_name', self::SOURCE)
            ->orderByDesc('created_at')
            ->get();
    }

    /** @param  Collection<int, Content>  $existing */
    private function priorFor(Collection $existing, QuestionCluster $cluster): ?Content
    {
        $phrasings = array_map(fn (QuestionStat $v): string => $v->query, $cluster->variants);
        foreach ($existing as $row) {
            $q = $row->meta['question'] ?? null;
            if (! is_array($q)) {
                continue;
            }
            $known = array_merge([(string) ($q['query'] ?? '')], is_array($q['phrasings'] ?? null) ? $q['phrasings'] : []);
            if (array_intersect($known, $phrasings) !== []) {
                return $row;
            }
        }

        return null;
    }

    private function uniqueSlug(string $siteId, string $title): string
    {
        $base = Str::slug($title) ?: 'question-post';
        $slug = $base;
        $suffix = 1;
        while (Content::withoutGlobalScope(SiteScope::class)->withTrashed()->where('site_id', $siteId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
