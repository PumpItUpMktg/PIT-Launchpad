<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Jobs\SyncSiloCategories;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\PostPublisher;
use App\Publishing\PublishSiloService;
use Illuminate\Console\Command;

/**
 * Project a site's silo tree into WP categories on demand — the §4 silos → /silo push, roots-first
 * and idempotent by ULID. The Finalize-time {@see SyncSiloCategories} does this for new
 * tenants; this command is the backfill/RESYNC for a tenant finalized BEFORE that trigger existed
 * (or whose earlier push 404'd against a stale companion plugin) — once its plugin is current and a
 * WP connection is wired, run this to create/repair the categories without re-finalizing.
 *
 * Because the push upserts by silo ULID, re-running it also RENAMES a silo's category from the
 * plugin's lazy "Silo {ulid}" placeholder (created when a post published before /silo had run) to the
 * silo's real name — the fix for a tenant whose posts show a `silo{ulid}` category.
 *
 * `--repush-content` closes the loop for ALREADY-LIVE posts: renaming the correct term doesn't move a
 * post the plugin lazily attached to a SEPARATE placeholder term, so after syncing categories this
 * re-pushes the site's live posts (synchronously, idempotent by ULID — no worker needed) to re-apply
 * the corrected category to published content.
 *
 * Synchronous (no FPM clock on the console). Re-runnable safely.
 */
class SyncSiloCategoriesCommand extends Command
{
    protected $signature = 'launchpad:sync-silo-categories {site : Site id or brand name}
        {--repush-content : After syncing categories, re-push the site\'s live posts so the corrected category lands on already-published content}';

    protected $description = 'Push/resync a site\'s silo tree to WordPress categories (roots-first, idempotent by ULID; renames "Silo {ulid}" placeholders). --repush-content re-applies the fixed category to live posts.';

    public function handle(PublishSiloService $service, PostPublisher $publisher): int
    {
        $site = Site::query()->find($this->argument('site'))
            ?? Site::query()->where('brand_name', $this->argument('site'))->first();

        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $count = $service->publishSite($site);

        $this->info($count === 0
            ? "No silos to push for {$site->brand_name}."
            : "Pushed {$count} silo categor(ies) for {$site->brand_name} (renamed any placeholders to their real names).");

        if ($this->option('repush-content')) {
            $this->repushLivePosts($site, $publisher);
        }

        return self::SUCCESS;
    }

    /**
     * Re-push the site's live posts so the just-corrected silo category lands on already-published
     * content. Idempotent by ULID (a re-push updates the same WP post); runs inline via PostPublisher,
     * so it works even with the queue worker down.
     */
    private function repushLivePosts(Site $site, PostPublisher $publisher): void
    {
        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereNotNull('silo_id') // only posts whose category comes from a silo
            ->orderBy('updated_at')
            ->get();

        if ($posts->isEmpty()) {
            $this->line('No live silo posts to re-push.');

            return;
        }

        $this->line("Re-pushing {$posts->count()} live post(s) so the corrected category applies…");

        $done = 0;
        $failed = 0;
        foreach ($posts as $post) {
            if ($publisher->publish($post)->isPublished()) {
                $done++;
            } else {
                $failed++;
            }
        }

        $this->info("Re-pushed {$done} live post(s)".($failed > 0 ? ", {$failed} failed (see logs)" : '').'.');
    }
}
