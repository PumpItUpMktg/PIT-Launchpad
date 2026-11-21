<?php

namespace App\ContentEngine\Questions;

use App\Integrations\UrlInspection\GoogleIndexInspector;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\UrlInspection\IndexStatus;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Has Google crawled a refreshed post since the refreshed content went live? Google's URL Inspection reports a
 * last-crawl time per URL; the index sync stores it on `page_index_states` and the inspector caches the
 * verdict (14 days when indexed, 3 when pending), so the stored date can lag a real re-crawl. `for()` reads
 * what is stored and says how fresh the read is; `reinspect()` asks Google again for this one URL, cache
 * bypassed, and writes the verdict back — the daily checkpoints and the row's "Check Google now" both use it.
 */
class RefreshCrawlCheck
{
    public function __construct(private readonly IndexInspector $inspector) {}

    /**
     * @return array{
     *   state: string, since: string, last_crawled_at: ?string, last_inspected_at: ?string, label: string, detail: string,
     *   checking: bool, verdict: ?string, inspection_link: ?string
     * }|null  state ∈ crawled | not_crawled | unchecked | none; null for a post never refreshed
     */
    public function for(Site $site, Content $post, ?Carbon $now = null): ?array
    {
        $since = RefreshMarks::since($post);
        if ($since === null) {
            return null;
        }
        $now ??= Carbon::now();
        $url = PublicUrl::forContent($site->domain_url, $post);

        // The freshest read wins: the inspector's cache (what the cards show) or the stored row.
        $cached = $url !== null ? $this->inspector->cached($site, $url) : null;
        $row = $this->row($site, $post, $url);
        $lastCrawled = self::latest($cached?->lastCrawledAt, $row?->last_crawled_at);
        $lastInspected = self::latest($cached?->inspectedAt, $row?->last_inspected_at);

        $requested = is_array($post->meta['refresh_crawl'] ?? null) && isset($post->meta['refresh_crawl']['requested_at'])
            ? Carbon::parse((string) $post->meta['refresh_crawl']['requested_at']) : null;
        $checking = $requested !== null && ($lastInspected === null || $lastInspected->lt($requested)) && $requested->gt($now->copy()->subHours(2));

        if ($lastInspected === null) {
            $state = 'none';
            $label = 'Not checked yet';
            $detail = 'Google has not been asked about this URL since it went live.';
        } elseif ($lastCrawled !== null && $lastCrawled->gte($since)) {
            $state = 'crawled';
            $label = 'Crawled since refresh · '.$lastCrawled->format('M j');
            $detail = 'Google reports a crawl on '.$lastCrawled->format('M j').', after the refreshed content went live on '.$since->format('M j').'. Checked '.$lastInspected->format('M j').'.';
        } elseif ($lastInspected->lt($since)) {
            $state = 'unchecked';
            $label = 'Not checked since refresh';
            $detail = 'The last inspection ('.$lastInspected->format('M j').') predates the refresh ('.$since->format('M j').')'.($lastCrawled !== null ? '; last known crawl '.$lastCrawled->format('M j') : '').'. Re-check to ask Google again.';
        } else {
            $state = 'not_crawled';
            $label = 'Not crawled since refresh';
            $detail = 'Checked '.$lastInspected->format('M j').': Google\'s last crawl is '.($lastCrawled !== null ? $lastCrawled->format('M j') : 'unknown').', before the refreshed content went live on '.$since->format('M j').'.';
        }

        return [
            'state' => $state,
            'since' => $since->toDateString(),
            'last_crawled_at' => $lastCrawled?->toDateString(),
            'last_inspected_at' => $lastInspected?->toDateString(),
            'label' => $label,
            'detail' => $detail,
            'checking' => $checking,
            'verdict' => $cached->verdict ?? $row?->index_verdict,
            'inspection_link' => $cached->resultLink ?? $row?->inspection_link,
        ];
    }

    /** Ask Google again for this one URL (cache bypassed) and write the verdict + crawl time back. Null when not connected / quota. */
    public function reinspect(Site $site, Content $post): ?IndexStatus
    {
        $url = PublicUrl::forContent($site->domain_url, $post);
        if ($url === null || ! $this->inspector->connected($site)) {
            return null;
        }
        if ($this->inspector instanceof GoogleIndexInspector) {
            $this->inspector->forget($site, $url);
        }
        $status = $this->inspector->inspect($site, $url);
        if ($status === null) {
            return null;
        }

        $now = Carbon::now();
        $normalized = UrlNormalizer::url($url);
        if ($normalized !== '') {
            DB::table('page_index_states')->upsert([[
                'id' => (string) Str::ulid(),
                'site_id' => $site->id,
                'content_id' => $post->id,
                'origin' => 'content',
                'url' => $url,
                'url_normalized' => $normalized,
                'coverage_state' => $status->coverageState,
                'index_verdict' => $status->indexed() ? 'PASS' : $status->state->value,
                'canonical_url' => $status->googleCanonical,
                'inspection_link' => $status->resultLink,
                'last_crawled_at' => $status->lastCrawledAt,
                'last_inspected_at' => $status->inspectedAt ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['site_id', 'url_normalized'], ['content_id', 'origin', 'coverage_state', 'index_verdict', 'canonical_url', 'inspection_link', 'last_crawled_at', 'last_inspected_at', 'updated_at']);
        }

        $meta = is_array($post->meta) ? $post->meta : [];
        $meta['refresh_crawl'] = ['checked_at' => $now->toIso8601String(), 'last_crawled_at' => $status->lastCrawledAt?->toIso8601String()];
        $post->forceFill(['meta' => $meta])->save();

        return $status;
    }

    private function row(Site $site, Content $post, ?string $url): ?PageIndexState
    {
        $q = PageIndexState::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
        $normalized = $url !== null ? UrlNormalizer::url($url) : '';

        return $q->where(fn ($w) => $w->where('content_id', $post->id)->when($normalized !== '', fn ($x) => $x->orWhere('url_normalized', $normalized)))
            ->orderByDesc('last_inspected_at')
            ->first();
    }

    private static function latest(?Carbon $a, mixed $b): ?Carbon
    {
        $b = $b instanceof \DateTimeInterface ? Carbon::instance($b) : (is_string($b) && $b !== '' ? Carbon::parse($b) : null);
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a->gte($b) ? $a : $b;
    }
}
