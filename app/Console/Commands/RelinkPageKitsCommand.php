<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\WireframeKit;
use App\SiloCreator\PillarFactory;
use App\Standard\StandardKit;
use Illuminate\Console\Command;

/**
 * Diagnose / repair page Content that has NO wireframe kit linked.
 *
 * A page materialized before its wireframe kit existed in the DB (the classic case: the
 * standard-page kits weren't seeded on the environment yet — run {@see SyncKitsCommand}
 * FIRST so the kit records exist, or `resolve()` finds nothing and this reports "no kit
 * resolves yet") is created with `wireframe_kit_id = null` and, because materialization is
 * idempotent by content_id, is never re-resolved. A kit-less page can't be drafted (the
 * drafter fills slots off the kit schema), so it sits held as "composer pending" and never
 * generates — even after its composer has shipped.
 *
 * This command relinks each kit-less page to the kit it SHOULD have (standard pages by
 * `standard_type`, every other page by `page_type` — exactly the materializer's logic). It
 * is read-only by default (a report); `--apply` writes the kit id/version. It only ever
 * FILLS a null link — it never overwrites a page that already has a kit. After `--apply`,
 * the pages become generatable — generate, review, and publish them.
 */
class RelinkPageKitsCommand extends Command
{
    protected $signature = 'launchpad:relink-page-kits {site : Site id (ULID) or exact brand_name} {--apply : Write the resolved kit id (default: dry-run report only)}';

    protected $description = 'Relink page Content that has no wireframe kit (so it can draft + publish its designed layout). Run launchpad:sync-kits first. Dry-run unless --apply.';

    public function handle(): int
    {
        $site = Site::query()->find((string) $this->argument('site'))
            ?? Site::query()->where('brand_name', (string) $this->argument('site'))->first();

        if ($site === null) {
            $this->error("Site not found: {$this->argument('site')} (pass a Site id or exact brand_name).");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info("Site '{$site->brand_name}' ({$site->id}) — ".($apply ? 'APPLY (writing kit links)' : 'dry-run (report only)'));

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->orderBy('page_type')
            ->orderBy('slug')
            ->get();

        $linked = 0;
        $missing = 0;
        $unresolvable = 0;

        foreach ($pages as $page) {
            if ($page->wireframe_kit_id !== null) {
                continue; // already linked — never overwritten
            }

            $kit = $this->resolveKit($page, $site->id);
            if ($kit === null) {
                $unresolvable++;
                $std = $page->standard_type instanceof StandardPageType ? $page->standard_type->value : 'none';
                $this->line("  - {$page->slug} (page_type={$page->page_type->value}, standard_type={$std}) — no kit record found (run launchpad:sync-kits, or its composer hasn't shipped)");

                continue;
            }

            $missing++;
            if ($apply) {
                $page->forceFill([
                    'wireframe_kit_id' => $kit->id,
                    'wireframe_kit_version' => $kit->version,
                ])->save();
                $linked++;
                $this->line("  ✓ {$page->slug} → {$kit->name} v{$kit->version} (linked)");
            } else {
                $this->line("  • {$page->slug} → would link {$kit->name} v{$kit->version} (currently kit-less)");
            }
        }

        $this->newLine();
        if ($missing === 0 && $unresolvable === 0) {
            $this->info('All pages already carry a wireframe kit — none publish flat for this reason.');
        } elseif ($apply) {
            $this->info("Linked {$linked} kit-less page(s). They're now generatable — generate, review, and publish them.");
        } else {
            $this->warn("{$missing} page(s) have no kit and stay held as 'composer pending'. Re-run with --apply to relink.");
        }
        if ($unresolvable > 0) {
            $this->line("{$unresolvable} page(s) have no kit RECORD yet — run launchpad:sync-kits so the kits exist, then re-run this.");
        }

        return self::SUCCESS;
    }

    private function resolveKit(Content $page, string $siteId): ?WireframeKit
    {
        // standard_type / page_type are model-cast to their enums (or null).
        if ($page->standard_type instanceof StandardPageType) {
            return StandardKit::resolve($page->standard_type, $siteId);
        }

        return $page->page_type !== null
            ? PillarFactory::resolveKit($page->page_type, $siteId)
            : null;
    }
}
