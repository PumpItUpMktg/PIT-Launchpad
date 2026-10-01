<?php

namespace App\Jobs;

use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\TownPages\PrioritySectionDrafter;
use App\TownPages\PrioritySectionStatus;
use App\TownPages\PrioritySectionWriter;
use App\TownPages\TownSectionPlan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Draft ONE town page's priority sections on the worker (one model call), store them, and — when asked —
 * push the page so they go live. Dispatched per page by `launchpad:priority-sections --execute`, so a
 * site's worth of pages drafts as a stream of small jobs rather than one long console run. Unique per page
 * while queued; tries=1 (a failed draft records its cause on the page; the next run retries it).
 */
class DraftPrioritySections implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $contentId,
        public readonly bool $repush = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->contentId;
    }

    /**
     * Stamp the page "queued" (the Service Areas town panel reads it) and dispatch. The writer clears the
     * stamp when the sections land or the draft fails, so a page never reads queued after the worker is done.
     */
    public static function enqueue(Content $page, bool $repush = false): void
    {
        $meta = is_array($page->meta) ? $page->meta : [];
        $meta[PrioritySectionStatus::QUEUED_KEY] = now()->toIso8601String();
        $page->forceFill(['meta' => $meta])->save();
        self::dispatch((string) $page->id, $repush);
    }

    public function handle(TownSectionPlan $planner, PrioritySectionDrafter $drafter, PrioritySectionWriter $writer): void
    {
        $page = Content::withoutGlobalScope(SiteScope::class)->find($this->contentId);
        $site = $page === null ? null : Site::withoutGlobalScope(SiteScope::class)->find($page->site_id);
        if ($page === null || $site === null) {
            return;
        }

        $population = (int) (CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('geo_id', (string) $page->geo_id)->value('population') ?? 0);
        // Draft only what lags; a keyword the town already ranks page 1 for keeps the section it has (or
        // gets none) — a push never rewrites a ranking the page already holds.
        $plan = $planner->for($site, $page, $population);
        $draft = $plan['draft'];
        $expected = $plan['expected'];

        try {
            $drafted = $draft === [] ? [] : $drafter->parse($drafter->attempt($page, $draft), $draft);
            $result = $writer->write($page, $drafted, $expected);
        } catch (Throwable $e) {
            $writer->fail($page, $e::class.': '.$e->getMessage());
            Log::warning('Priority sections: draft failed', ['content_id' => $page->id, 'error' => $e->getMessage()]);

            return;
        }

        if ($draft !== [] && $result['stored'] === [] && $result['refused'] === []) {
            $writer->fail($page, 'The draft came back without a usable section.');

            return;
        }
        if ($result['refused'] !== []) {
            Log::info('Priority sections: templated section refused', ['content_id' => $page->id, 'refused' => $result['refused']]);
        }
        // Push only when the page changed: a town that ranks for everything (nothing drafted, nothing
        // dropped) is left exactly as it is.
        $changed = $result['stored'] !== [] || $result['dropped'] !== [];
        if ($this->repush && $changed && $page->wp_post_id !== null) {
            PublishContent::dispatch((string) $page->id);
        }
    }
}
