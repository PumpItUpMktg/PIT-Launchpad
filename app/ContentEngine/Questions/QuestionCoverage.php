<?php

namespace App\ContentEngine\Questions;

use App\ContentEngine\NearDuplicateDetector;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Decides what a site should do about a question topic from where it ALREADY ranks for it — the Search
 * Console store names the URL that carried the impressions, which is the authoritative "we have content"
 * signal. The URL is resolved to our content by path; a post at or above the covered bound is covered,
 * a post below it is a refresh of that post, and anything else (home page, hub, service page, a legacy
 * URL, no URL) means a dedicated post is missing — unless a live post reads as the same topic by title
 * (the §6a near-dup detector), in which case that post is the refresh. Read-only.
 */
class QuestionCoverage
{
    /** @var array<string, Collection<int, Content>> */
    private array $livePosts = [];

    /** @var array<string, Collection<int, Content>> */
    private array $published = [];

    public function __construct(private readonly NearDuplicateDetector $nearDup) {}

    public function verdict(Site $site, QuestionCluster $cluster): QuestionVerdict
    {
        $coveredMax = (float) config('launchpad.questions.covered_max_position', 5);
        $url = $cluster->topUrl();
        $position = $cluster->topUrlPosition() ?? $cluster->position();

        $ranking = $url !== null ? $this->contentAt($site, $url) : null;
        if ($ranking !== null && $ranking->kind === ContentKind::Post) {
            if ($position !== null && $position <= $coveredMax) {
                return QuestionVerdict::covered($ranking, (string) $url, $position);
            }

            return QuestionVerdict::refresh($ranking, $url, $position, $position !== null
                ? sprintf('our post ranks #%s for it — refresh it', number_format($position, 1))
                : 'our post carries it without a recorded position — refresh it');
        }

        $twin = $this->twin($site, $cluster);
        if ($twin !== null) {
            [$post, $similarity] = $twin;

            return QuestionVerdict::refresh($post, $url, $position, sprintf(
                '%s ranks for it, but our post “%s” reads as the same topic — refresh it',
                $this->describe($ranking, $url),
                mb_substr((string) $post->title, 0, 60),
            ), $similarity);
        }

        return QuestionVerdict::author($url, $position, $url === null
            ? 'nothing of ours ranks for it'
            : sprintf('%s ranks for it (#%s), no post answers it', $this->describe($ranking, $url), $position !== null ? number_format($position, 1) : '—'));
    }

    /**
     * Our content at a URL, by path. Host-agnostic (www / http variants of the site resolve alike) and
     * tolerant of the two ways WordPress rewrites a slug we published: a numeric suffix when the slug
     * collided on its side ("…-cost-breakdown-10" for our "…-cost-breakdown"), and a category or date
     * prefix in front of the post slug. Exact path first; then the path with its trailing "-N" removed;
     * then the last segment alone. Published rows only — nothing else is on the live site.
     */
    public function contentAt(Site $site, string $url): ?Content
    {
        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
        if ($path === '') {
            return null;
        }
        $rows = $this->published($site);
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[trim((string) $row->slug, '/')] = $row;
        }

        if (isset($bySlug[$path])) {
            return $bySlug[$path];
        }
        $unsuffixed = preg_replace('/-\d+$/', '', $path) ?? $path;
        if ($unsuffixed !== $path && isset($bySlug[$unsuffixed])) {
            return $bySlug[$unsuffixed];
        }
        $last = (string) basename($path);
        $lastUnsuffixed = preg_replace('/-\d+$/', '', $last) ?? $last;
        foreach ([$last, $lastUnsuffixed] as $needle) {
            foreach ($bySlug as $slug => $row) {
                if ($slug !== '' && basename($slug) === $needle) {
                    return $row;
                }
            }
        }

        return null;
    }

    /** @return Collection<int, Content> */
    private function published(Site $site): Collection
    {
        return $this->published[(string) $site->id] ??= Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('slug')
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'kind', 'page_type', 'slug', 'title', 'status', 'meta']);
    }

    /**
     * A live post that reads as this question by title — the §6a detector's two signals read separately
     * and each held to its own floor (config `questions.twin_semantic` / `questions.twin_overlap`): a
     * near-identical title (token overlap) or a strong embedding match. The detector's blended "flag"
     * tier is too loose here — a question and a post that merely share "sump pump … cost" would pair,
     * and a wrong twin becomes a wrong refresh mark on a live post. Null when neither floor is met.
     *
     * @return array{0: Content, 1: float}|null
     */
    private function twin(Site $site, QuestionCluster $cluster): ?array
    {
        $posts = $this->livePosts[(string) $site->id] ??= Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'kind', 'slug', 'title', 'status', 'meta']);
        if ($posts->isEmpty()) {
            return null;
        }

        $text = implode(' ', array_map(fn (QuestionStat $v): string => $v->query, $cluster->variants));
        $result = $this->nearDup->detect($text, $posts);
        if ($result->similarToContentId === null) {
            return null;
        }
        $semanticFloor = (float) config('launchpad.questions.twin_semantic', 0.85);
        $overlapFloor = (float) config('launchpad.questions.twin_overlap', 0.6);
        if ($result->semanticSimilarity < $semanticFloor && $result->keywordOverlap < $overlapFloor) {
            return null;
        }
        $post = $posts->firstWhere('id', $result->similarToContentId);

        return $post !== null ? [$post, round($result->signal(), 3)] : null;
    }

    private function describe(?Content $content, ?string $url): string
    {
        if ($content === null) {
            $path = $url !== null ? trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/') : '';

            return $path === '' ? 'the home page' : "a URL outside the platform (/{$path})";
        }

        return match ($content->page_type?->value) {
            'home' => 'the home page',
            'hub' => 'the office hub',
            'service' => 'the service page',
            'location' => 'a town page',
            default => 'a page',
        };
    }
}
