<?php

namespace App\Publishing\Seo;

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\Claude\ClaudeClient;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * The on-demand "now-fixer" for pages already live with an off-target H1: it reworks ONLY the three SEO
 * surfaces — the H1 (hero_headline), the SEO title, and the meta description — so each leads with / uses
 * the page's target keyword, then re-publishes (idempotent by ULID, so the WP post + its publish date are
 * preserved; only the meta-blob changes). It does NOT re-draft the body or re-render images — the surgical
 * counterpart to a full regenerate.
 *
 * The rewrite runs on the cheap Haiku client (bound in AppServiceProvider) with a deterministic,
 * keyword-guaranteed fallback: if the model errors, can't be parsed, or returns a value that STILL omits
 * the keyword or busts the H1 budget, that field falls back to a keyword-led form — so a fix always leaves
 * the keyword present.
 */
class HeadlineKeywordFixer
{
    /** The H1 slot key across the commercial kits (matches {@see KeywordUsageAuditor}). */
    private const HERO_KEY = 'hero_headline';

    private const TITLE_MAX = 60;

    private const META_MAX = 155;

    private const HERO_MAX_FALLBACK = 70;

    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * Published service/hub pages whose H1 currently OMITS the target keyword — the pages this fixer targets.
     *
     * @return Collection<int, Content>
     */
    public function offTargetPages(Site $site): Collection
    {
        return Content::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('page_type', [PageType::Service->value, PageType::Hub->value])
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('target_keyword_id')
            ->with('targetKeyword')
            ->orderBy('slug')
            ->get()
            ->filter(fn (Content $p): bool => $this->needsFix($p))
            ->values();
    }

    public function needsFix(Content $page): bool
    {
        $keyword = $page->targetKeyword?->query;
        if (! is_string($keyword) || trim($keyword) === '') {
            return false;
        }

        return KeywordUsageAuditor::placement($keyword, $this->heroValue($page)) === KeywordUsageAuditor::ABSENT;
    }

    /**
     * Propose the keyword-led rewrite for one page. Null when the page has no target keyword.
     */
    public function propose(Content $page): ?HeadlineFix
    {
        $keyword = $page->targetKeyword?->query;
        if (! is_string($keyword) || trim($keyword) === '') {
            return null;
        }

        $oldH1 = $this->heroValue($page);
        $seo = $this->seo($page);
        $oldTitle = (string) ($seo['title'] ?? $page->title ?? '');
        $oldMeta = (string) ($seo['meta_description'] ?? '');
        $heroMax = $this->heroMax($page);

        [$h1, $title, $meta] = $this->rewrite($keyword, $oldH1, $oldTitle, $oldMeta, $heroMax, $page);

        return new HeadlineFix($page, $keyword, self::HERO_KEY, $oldH1, $h1, $oldTitle, $title, $oldMeta, $meta);
    }

    /**
     * Persist the rewrite onto the page (H1 slot + seo.title + seo.meta_description) and re-publish so the
     * live WP post's meta-blob refreshes. The publish is idempotent by ULID — the post and its published_at
     * are preserved.
     */
    public function apply(HeadlineFix $fix, ?string $actorId = null): void
    {
        $page = $fix->page;

        $slots = is_array($page->slot_payload) ? $page->slot_payload : [];
        $slots[$fix->heroKey] = $fix->newH1;

        $meta = is_array($page->meta) ? $page->meta : [];
        $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];
        $seo['title'] = $fix->newTitle;
        $seo['meta_description'] = $fix->newMeta;
        $meta['seo'] = $seo;

        $page->forceFill(['slot_payload' => $slots, 'meta' => $meta])->save();

        if ($page->wp_post_id !== null) {
            PublishContent::dispatch((string) $page->id, $actorId);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} [hero_headline, seo_title, meta_description]
     */
    private function rewrite(string $keyword, string $oldH1, string $oldTitle, string $oldMeta, int $heroMax, Content $page): array
    {
        $parsed = $this->askModel($keyword, $oldH1, $oldTitle, $oldMeta, $heroMax);

        $h1 = $this->acceptOrFallback($keyword, $parsed['hero_headline'] ?? null, $heroMax, $this->fallbackH1($keyword, $heroMax));
        $title = $this->acceptOrFallback($keyword, $parsed['seo_title'] ?? null, self::TITLE_MAX, $this->fallbackTitle($keyword));
        $meta = $this->acceptOrFallback($keyword, $parsed['meta_description'] ?? null, self::META_MAX, $this->fallbackMeta($keyword, $oldMeta, $page));

        return [$h1, $title, $meta];
    }

    /**
     * @return array<string, string>
     */
    private function askModel(string $keyword, string $oldH1, string $oldTitle, string $oldMeta, int $heroMax): array
    {
        $prompt = "Rewrite three SEO fields for a home-services page whose primary target keyword is \"{$keyword}\".\n"
            ."Rules: EACH field must contain \"{$keyword}\" verbatim. hero_headline and seo_title must LEAD with it, then a short benefit.\n"
            ."Budgets (hard): hero_headline <= {$heroMax} chars, seo_title <= ".self::TITLE_MAX.' chars, meta_description <= '.self::META_MAX." chars.\n"
            ."Keep the brand's plain, confident voice. Do NOT invent facts, prices, or locations.\n"
            ."Current values:\n- hero_headline: {$oldH1}\n- seo_title: {$oldTitle}\n- meta_description: {$oldMeta}\n"
            .'Return ONLY a JSON object: {"hero_headline": "...", "seo_title": "...", "meta_description": "..."}';

        try {
            $raw = $this->claude->complete($prompt);
        } catch (Throwable) {
            return [];
        }

        $json = $this->extractJson($raw);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_map(fn ($v): string => is_string($v) ? trim($v) : '', $decoded) : [];
    }

    /** Accept the model's value only if it contains the keyword AND fits the budget; else the fallback. */
    private function acceptOrFallback(string $keyword, ?string $value, int $max, string $fallback): string
    {
        if (is_string($value)
            && $value !== ''
            && mb_strlen($value) <= $max
            && KeywordUsageAuditor::placement($keyword, $value) !== KeywordUsageAuditor::ABSENT) {
            return $value;
        }

        return $fallback;
    }

    private function fallbackH1(string $keyword, int $heroMax): string
    {
        return mb_substr($this->titleCase($keyword), 0, $heroMax);
    }

    private function fallbackTitle(string $keyword): string
    {
        return mb_substr($this->titleCase($keyword), 0, self::TITLE_MAX);
    }

    private function fallbackMeta(string $keyword, string $oldMeta, Content $page): string
    {
        $tail = trim($oldMeta) !== '' ? $oldMeta : $this->heroSubhead($page);
        $lead = $this->titleCase($keyword).' — ';
        // If the old copy already carries the keyword, keep it; otherwise prepend a keyword-led lead-in.
        $base = KeywordUsageAuditor::placement($keyword, $tail) !== KeywordUsageAuditor::ABSENT
            ? $tail
            : $lead.$tail;

        return trim(mb_substr($base, 0, self::META_MAX)) !== '' ? mb_substr($base, 0, self::META_MAX) : $this->titleCase($keyword);
    }

    private function titleCase(string $s): string
    {
        // Already mixed-case (a service name like "Sump Pump Maintenance") → keep as authored.
        return $s !== mb_strtolower($s) ? $s : ucwords($s);
    }

    private function extractJson(string $raw): string
    {
        $raw = trim($raw);
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        return $start !== false && $end !== false && $end >= $start ? substr($raw, $start, $end - $start + 1) : '{}';
    }

    private function heroValue(Content $page): string
    {
        $slots = is_array($page->slot_payload) ? $page->slot_payload : [];
        $raw = $slots[self::HERO_KEY] ?? '';

        return is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;
    }

    private function heroSubhead(Content $page): string
    {
        $slots = is_array($page->slot_payload) ? $page->slot_payload : [];

        return is_string($slots['hero_subhead'] ?? null) ? $slots['hero_subhead'] : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function seo(Content $page): array
    {
        $meta = is_array($page->meta) ? $page->meta : [];

        return is_array($meta['seo'] ?? null) ? $meta['seo'] : [];
    }

    private function heroMax(Content $page): int
    {
        $max = $page->wireframe_kit_id !== null
            ? $page->wireframeKit?->schema()->slot(self::HERO_KEY)?->constraints->maxLength
            : null;

        return is_int($max) && $max > 0 ? $max : self::HERO_MAX_FALLBACK;
    }
}
