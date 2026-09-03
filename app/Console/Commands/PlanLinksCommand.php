<?php

namespace App\Console\Commands;

use App\Enums\LinkSourceType;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\LinkPlanActions;
use App\Publishing\Links\LinkPlanBuilder;
use Illuminate\Console\Command;

/**
 * Propose a link plan for a market's just-unlocked tier — the five-source inbound-link plan for its
 * newly-built town pages. PROPOSES ONLY (persists a Proposed plan); an operator approves + applies it on the
 * Link plans board (or via {@see LinkPlanActions}). Nothing is written and no URL is submitted
 * to IndexNow here.
 */
class PlanLinksCommand extends Command
{
    protected $signature = 'launchpad:plan-links {site : site id} {--market= : the market Location id} {--tier= : size tier (major|large|medium|small); omit for ungrouped}';

    protected $description = 'Propose a five-source link plan for a market tier\'s new town pages (proposal only — operator approves + applies).';

    public function handle(LinkPlanBuilder $builder): int
    {
        $site = Site::withoutGlobalScopes()->find($this->argument('site'));
        if ($site === null) {
            $this->error('No such site.');

            return self::FAILURE;
        }

        $marketId = $this->option('market');
        $markets = $marketId !== null
            ? Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($marketId)->get()
            : Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

        if ($markets->isEmpty()) {
            $this->error('No matching market Location for this site.');

            return self::FAILURE;
        }

        $tier = $this->option('tier'); // null → ungrouped band
        $total = 0;
        foreach ($markets as $market) {
            $plan = $builder->propose($site, $market, $tier);
            $items = $plan->items;
            if ($items->isEmpty()) {
                continue;
            }
            $total += $items->count();

            $this->newLine();
            $this->line("<info>{$market->name}</info> · tier ".($tier ?? 'ungrouped')." — plan {$plan->id}");
            foreach (LinkSourceType::cases() as $type) {
                $n = $items->where('source_type', $type)->count();
                if ($n > 0) {
                    $this->line(sprintf('  • %-22s %d link(s)', $type->label(), $n));
                }
            }
            $targets = $items->pluck('target_content_id')->unique()->count();
            $this->line("  → {$items->count()} proposed link(s) to {$targets} town page(s).");
        }

        $this->newLine();
        $this->info("Proposed {$total} link(s). Review + approve on the Link plans board; nothing written yet.");

        return self::SUCCESS;
    }
}
