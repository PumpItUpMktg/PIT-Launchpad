<?php

namespace App\Console\Commands;

use App\Build\TargetKeywordResolver;
use App\Enums\ContentKind;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Backfill the target keyword on pages materialized BEFORE the carry-over was wired (they were created
 * with `target_keyword_id = null` and, because materialize is idempotent and the draft path never sets
 * it, would stay null forever). For each such page it resolves its spoke's Pass-D `primary_keyword` to
 * a §5 Keyword and pins it — fixing the proof-editor rail immediately.
 *
 * Pairs with a regenerate: this sets the target so the rail reads it; regenerating the page then
 * re-grounds its CONTENT on the real keyword (grounding also reads target_keyword_id).
 */
class BackfillPageKeywordsCommand extends Command
{
    protected $signature = 'launchpad:backfill-page-keywords {site? : a Site id (default: all sites)}';

    protected $description = 'Set target_keyword_id on pages built before the keyword carry-over, from their spoke primary_keyword.';

    public function handle(TargetKeywordResolver $keywords): int
    {
        $siteId = $this->argument('site');
        $sites = $siteId !== null
            ? Site::where('id', (string) $siteId)->get()
            : Site::query()->get();

        if ($sites->isEmpty()) {
            $this->error('No matching site.');

            return self::FAILURE;
        }

        $total = 0;
        foreach ($sites as $site) {
            $pages = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('kind', ContentKind::Page->value)
                ->whereNull('target_keyword_id')
                ->get();

            $filled = 0;
            foreach ($pages as $page) {
                $spokeId = BuildPage::query()->where('content_id', $page->id)->value('spoke_id');
                $keyword = $keywords->forSpoke($site, $spokeId !== null ? (string) $spokeId : null, $page->silo_id);
                if ($keyword === null) {
                    continue;
                }
                $keywords->link($page, $keyword);
                $filled++;
            }

            if ($filled > 0) {
                $this->info(sprintf('%s — backfilled %d page(s).', $site->brand_name, $filled));
            }
            $total += $filled;
        }

        $this->info("Done — {$total} page(s) now carry a target keyword. Regenerate them to re-ground the content.");

        return self::SUCCESS;
    }
}
