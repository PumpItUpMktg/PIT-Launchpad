<?php

namespace App\Console\Commands;

use App\ContentEngine\Questions\RefreshMarks;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Jobs\ReinspectRefreshedPost;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Re-ask Google about refreshed posts at fixed checkpoints after the republish (default 3 and 10 days), cache
 * bypassed, so "crawled since refresh" reads from a real inspection rather than a two-week-old cached verdict.
 * A handful of URL Inspection calls per refresh batch against the 2,000/day quota. Report-first.
 */
class ReinspectRefreshedCommand extends Command
{
    protected $signature = 'launchpad:reinspect-refreshed
        {--site= : Limit to one site id or brand name}
        {--limit=50 : Most URLs to queue per run (quota guard)}
        {--execute : Queue the inspections (report-only by default)}';

    protected $description = 'Re-inspect refreshed posts at the checkpoints after their republish (3 and 10 days by default) so the crawl check reads a fresh Google answer. Report-first; --execute queues.';

    public function handle(): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::query()->get();
        }
        $checkpoints = array_values(array_filter(array_map('intval', (array) config('launchpad.questions.crawl_checkpoints', [3, 10])), fn (int $d): bool => $d > 0));
        $horizon = max($checkpoints) + 11;
        $limit = max(1, (int) $this->option('limit'));
        $execute = (bool) $this->option('execute');
        $now = Carbon::now();
        $queued = 0;

        $this->info(($execute ? 'Queueing' : 'Read-only ·').' re-inspections for refreshed posts at '.implode(' / ', $checkpoints).' days after republish.');
        foreach ($sites as $site) {
            $posts = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('kind', ContentKind::Post->value)
                ->where('status', ContentStatus::Published->value)
                ->get()
                ->filter(fn (Content $c): bool => RefreshMarks::since($c) !== null && RefreshMarks::since($c)->gt($now->copy()->subDays($horizon)));
            if ($posts->isEmpty()) {
                continue;
            }
            $inspected = PageIndexState::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->whereIn('content_id', $posts->pluck('id'))
                ->groupBy('content_id')->selectRaw('content_id, max(last_inspected_at) as last_inspected_at')
                ->pluck('last_inspected_at', 'content_id');

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) · {$posts->count()} refreshed post(s) inside the window");
            foreach ($posts as $post) {
                $since = RefreshMarks::since($post);
                $last = isset($inspected[$post->id]) ? Carbon::parse((string) $inspected[$post->id]) : null;
                $due = null;
                foreach ($checkpoints as $cp) {
                    $at = $since->copy()->addDays($cp);
                    if ($at->lte($now) && ($last === null || $last->lt($at))) {
                        $due = $cp;
                    }
                }
                if ($due === null) {
                    $this->line(sprintf('    %-60s republished %s · last inspected %s · nothing due', mb_substr((string) $post->title, 0, 60), $since->format('M j'), $last?->format('M j') ?? 'never'));

                    continue;
                }
                if ($queued >= $limit) {
                    $this->line(sprintf('    <fg=yellow>%-60s</> due (day %d) — over the per-run limit, next run', mb_substr((string) $post->title, 0, 60), $due));

                    continue;
                }
                $queued++;
                if ($execute) {
                    ReinspectRefreshedPost::enqueue($post);
                }
                $this->line(sprintf('    <fg=green>%-60s</> republished %s · last inspected %s · day-%d check %s', mb_substr((string) $post->title, 0, 60), $since->format('M j'), $last?->format('M j') ?? 'never', $due, $execute ? 'queued' : 'due'));
            }
        }

        $this->newLine();
        $this->line($execute ? "<info>{$queued}</info> inspection(s) queued." : "<info>{$queued}</info> inspection(s) due — nothing queued; pass --execute.");

        return self::SUCCESS;
    }
}
