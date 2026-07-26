<?php

namespace App\Console\Commands;

use App\ContentEngine\Reconcile\PostSiloReconciler;
use App\ContentEngine\Reconcile\PostTownTagger;
use App\Enums\ContentStatus;
use App\Jobs\PublishContent;
use App\Jobs\SyncSiloCategories;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Re-connect a site's blog posts to the current silo tree (the §B post→silo cascade, run standalone).
 * After a §4 rebuild, posts point at deleted silos and publish Uncategorized; this re-points them, and
 * with --repush re-pushes the reconnected LIVE posts so WordPress re-assigns the (correct) silo category.
 * --sync-categories first ensures every silo has its WP category term to assign to.
 *
 * Idempotent and safe to re-run. This is the backfill for the current Uncategorized posts and, later, a
 * stage in the orchestrated "Rebuild & reconcile".
 */
class ReconcilePostSilosCommand extends Command
{
    protected $signature = 'launchpad:reconcile-post-silos {site : Site id or brand name}
        {--repush : Re-push the reconnected LIVE posts so WordPress re-assigns their category}
        {--sync-categories : Ensure every silo has its WordPress category term first}
        {--towns : Also (re)tag posts with the coverage towns they mention (§B lp_area taxonomy)}';

    protected $description = 'Re-point blog posts at the current silo tree (fixes Uncategorized after a §4 rebuild).';

    public function handle(PostSiloReconciler $reconciler, PostTownTagger $tagger): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))->orWhere('brand_name', $this->argument('site'))->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        if ($this->option('sync-categories')) {
            SyncSiloCategories::enqueue($site);
            $this->line('Queued silo → WordPress category sync.');
        }

        $result = $reconciler->reconcile($site);

        $this->line(sprintf(
            '<info>%s</info> — %d post(s) re-routed to a current silo, %d left unrouted, %d already correct.',
            $site->brand_name, count($result['rerouted']), $result['orphaned'], $result['unchanged'],
        ));

        if ($this->option('towns')) {
            $towns = $tagger->tag($site);
            $this->line(sprintf(
                'Towns — %d post(s) tagged, %d tag(s) added, %d removed.',
                $towns['posts_tagged'], $towns['tags_added'], $towns['tags_removed'],
            ));
        }

        if ($this->option('repush') && $result['rerouted'] !== []) {
            $live = Content::withoutGlobalScope(SiteScope::class)
                ->whereIn('id', $result['rerouted'])
                ->where('status', ContentStatus::Published->value)
                ->pluck('id');

            foreach ($live as $id) {
                PublishContent::dispatch((string) $id);
            }
            $this->line("Queued a re-push for {$live->count()} live post(s) — they will re-categorize in WordPress.");
        }

        return self::SUCCESS;
    }
}
