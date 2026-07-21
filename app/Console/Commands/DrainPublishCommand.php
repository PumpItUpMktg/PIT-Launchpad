<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\PostPublisher;
use Illuminate\Console\Command;

/**
 * The stalled-worker escape hatch: publish every in-flight post (approved / rendering / pushing) for
 * a site SYNCHRONOUSLY, right here on the console — no queue worker required. When the background
 * worker is down, approved posts pile up at "queued to publish" forever; this drains that backlog by
 * running the same proven PublishContentService inline (via PostPublisher, so the WP-connection gate
 * and idempotent-by-ULID re-push still apply). Safe to re-run: a post already published is left alone.
 *
 * This is a manual recovery tool, not a substitute for a running worker — fix the worker (Horizon /
 * queue:work) so publishing is automatic again.
 */
class DrainPublishCommand extends Command
{
    protected $signature = 'launchpad:drain-publish {site : Site id or brand name} {--dry-run : List the stuck posts without publishing}';

    protected $description = 'Publish a site\'s in-flight (stuck "queued to publish") posts synchronously — the stalled-worker escape hatch.';

    public function handle(PostPublisher $publisher): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))
            ->orWhere('brand_name', $this->argument('site'))
            ->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        $inflight = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereIn('status', [
                ContentStatus::Approved->value,
                ContentStatus::Rendering->value,
                ContentStatus::Publishing->value,
            ])
            ->orderBy('updated_at')
            ->get();

        if ($inflight->isEmpty()) {
            $this->info("{$site->brand_name}: nothing in flight — no stuck posts to publish.");

            return self::SUCCESS;
        }

        $this->line("<info>{$site->brand_name}</info> — {$inflight->count()} post(s) in flight.");

        if ($this->option('dry-run')) {
            foreach ($inflight as $post) {
                $this->line("  • {$post->title}  ·  {$post->status->value}");
            }
            $this->comment('Dry run — nothing published. Re-run without --dry-run to publish these inline.');

            return self::SUCCESS;
        }

        $published = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($inflight as $post) {
            $result = $publisher->publish($post);

            if ($result->isPublished()) {
                $published++;
                $this->line("  <info>✓</info> {$post->title} → wp #{$result->wpPostId}");
            } elseif ($result->wasSkipped()) {
                $skipped++;
                $this->line("  <comment>–</comment> {$post->title}: {$result->message}");
            } else {
                $failed++;
                $this->line("  <error>✗</error> {$post->title}: {$result->message}");
            }
        }

        $this->newLine();
        $this->line("Done — {$published} published, {$skipped} skipped, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
