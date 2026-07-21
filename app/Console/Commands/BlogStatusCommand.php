<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only publish-state doctor for a site's blog: per published post, what actually got sent to
 * WordPress — slug, its silo category (and whether that category is MAPPED in WP or still a lazy
 * "Silo {ulid}" placeholder), the category description source, and the hero image's render status
 * (succeeded + URL / failed + reason / no spec at all). It answers "why is the category/slug/
 * description/image wrong?" from the control-plane side without touching anything.
 */
class BlogStatusCommand extends Command
{
    protected $signature = 'launchpad:blog-status {site : Site id or brand name}';

    protected $description = 'Report each published blog post\'s slug, silo category (+ description), and hero image render state.';

    public function handle(): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))
            ->orWhere('brand_name', $this->argument('site'))
            ->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->orderBy('title')
            ->get();

        $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$posts->count()} published post(s)");

        $this->queueHealth($site);

        if ($posts->isEmpty()) {
            $this->warn('Nothing published yet.');

            return self::SUCCESS;
        }

        foreach ($posts as $post) {
            $this->newLine();
            $this->line("• <comment>{$post->title}</comment>");
            $this->line('  slug: '.($post->slug ?: '— (missing)').'  ·  wp_post_id: '.($post->wp_post_id ?? '— (not on WP)'));
            [$category, $description] = $this->categoryLines($post);
            $this->line('  category: '.$category);
            $this->line('  category description: '.$description);
            $this->line('  hero image: '.$this->image($post));
        }

        $this->newLine();
        $this->line('Fixes — category name/description: <info>launchpad:sync-silo-categories '.$site->id.'</info>; '
            .'a "no hero spec" post: re-generate then re-push; a "render failed" hero: check the fal key/R2 then re-push.');

        return self::SUCCESS;
    }

    /**
     * The publish-queue doctor: is anything stuck in flight, and is the worker even running? A post
     * moves Approved → Rendering → Publishing → Published the instant the PublishContent job runs, so
     * a post sitting at "approved" ("queued to publish" in the UI) means the job was dispatched but
     * never consumed — the classic stalled-worker symptom. We pair that per-site read with the raw
     * database-queue depth so "22 approved + 22 pending jobs + 0 failed" reads unambiguously as
     * "worker is down", vs "0 pending + 22 failed" as "worker runs but every push errors".
     */
    private function queueHealth(Site $site): void
    {
        $inflight = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereIn('status', [
                ContentStatus::Approved->value,
                ContentStatus::Rendering->value,
                ContentStatus::Publishing->value,
            ]);

        $approved = (clone $inflight)->where('status', ContentStatus::Approved->value);
        $approvedCount = (clone $approved)->count();
        $inflightCount = (clone $inflight)->count();
        $renderFailed = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)->where('status', ContentStatus::RenderFailed->value)->count();
        $publishFailed = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)->where('status', ContentStatus::PublishFailed->value)->count();

        [$pending, $failed, $queueNote] = $this->queueDepth();

        $this->newLine();
        $this->line('<info>Publish queue</info>');
        $this->line('  in flight (approved/rendering/pushing): '.$inflightCount
            .($approvedCount > 0 ? "  ·  {$approvedCount} stuck at \"queued to publish\"" : ''));

        if ($approvedCount > 0) {
            $oldest = (clone $approved)->min('updated_at');
            $this->line('  oldest \"queued to publish\": '.($oldest ?? '—').' (has not started rendering)');
        }
        if ($renderFailed > 0 || $publishFailed > 0) {
            $this->line("  surfaced failures: {$renderFailed} render_failed, {$publishFailed} publish_failed (re-push after fixing the cause)");
        }
        $this->line('  database queue: '.($queueNote ?? "{$pending} pending job(s), {$failed} failed job(s)"));

        // The verdict — the one line that says what to do.
        if ($approvedCount > 0 && $pending > 0) {
            $this->warn('  ⇒ Jobs are queued but not being processed — the queue worker is not running. '
                .'Start it (php artisan queue:work / Horizon) or drain now: launchpad:drain-publish '.$site->id);
        } elseif ($approvedCount > 0) {
            $this->warn('  ⇒ Posts are stuck at "queued to publish" with no pending job — re-dispatch with '
                .'launchpad:drain-publish '.$site->id.' (runs the publish inline, no worker needed).');
        } elseif ($failed > 0) {
            $this->warn('  ⇒ There are failed jobs — inspect with: php artisan queue:failed, then queue:retry all.');
        }
    }

    /**
     * Raw database-queue depth — pending rows on the queue + failed rows. Returns a note instead of
     * counts when the queue isn't database-backed (sync/redis/sqs), since then there's no table to read.
     *
     * @return array{0: int, 1: int, 2: string|null} [pending, failed, note-or-null]
     */
    private function queueDepth(): array
    {
        $default = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$default}.driver");
        $failedTable = (string) config('queue.failed.table', 'failed_jobs');
        $failed = $this->tableExists($failedTable) ? (int) DB::table($failedTable)->count() : 0;

        if ($driver !== 'database') {
            return [0, $failed, "driver is \"{$driver}\" (not database) — {$failed} failed job(s); pending depth not readable here"];
        }

        $jobsTable = (string) config("queue.connections.{$default}.table", 'jobs');
        $pending = $this->tableExists($jobsTable) ? (int) DB::table($jobsTable)->count() : 0;

        return [$pending, $failed, null];
    }

    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{0: string, 1: string} [category line, description line]
     */
    private function categoryLines(Content $post): array
    {
        $siloId = $post->matched_silo_id ?? $post->silo_id;
        if ($siloId === null) {
            return ['— (no silo routed)', '—'];
        }

        $silo = Silo::withoutGlobalScope(SiteScope::class)->find($siloId);
        if ($silo === null) {
            return ['— (silo missing)', '—'];
        }

        $mapped = $silo->wp_category_id !== null
            ? 'mapped → wp_category_id '.$silo->wp_category_id
            : 'NOT mapped in WP → shows as a "Silo '.$silo->id.'" placeholder (run sync-silo-categories)';

        $desc = $this->pillarDescription($silo);
        $descLine = $desc !== '' ? '"'.$desc.'"' : '— (no pillar page / no meta description)';

        return [$silo->name.'  ·  '.$mapped, $descLine];
    }

    private function pillarDescription(Silo $silo): string
    {
        if ($silo->pillar_content_id === null) {
            return '';
        }
        $pillar = Content::withoutGlobalScope(SiteScope::class)->find($silo->pillar_content_id);
        $seo = is_array($pillar?->meta['seo'] ?? null) ? $pillar->meta['seo'] : [];

        return trim((string) ($seo['meta_description'] ?? ''));
    }

    private function image(Content $post): string
    {
        $specs = is_array($post->meta['image_specs'] ?? null) ? $post->meta['image_specs'] : [];
        $hasHeroSpec = collect($specs)->contains(fn ($s) => is_array($s) && ($s['slot'] ?? '') === 'hero_image');

        if (! $hasHeroSpec) {
            return 'NO hero spec in the draft (drafted before the hero-image fix) → re-generate the post, then re-push';
        }

        $job = RenderJob::withoutGlobalScope(SiteScope::class)
            ->where('content_id', $post->id)
            ->where('slot', 'hero_image')
            ->first();

        if ($job === null) {
            return 'hero spec present, but never rendered → re-push to render it';
        }

        return match (true) {
            $job->isRendered() => 'rendered ✓ '.($job->toImageObject()['url'] ?? ''),
            $job->hasFailed() => 'RENDER FAILED after '.$job->attempts.' attempt(s): '.($job->error ?? 'unknown').' → check the fal key / R2 access, then re-push',
            default => 'render '.$job->status->value.' (in flight)',
        };
    }
}
