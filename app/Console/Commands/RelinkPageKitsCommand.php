<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\WireframeKit;
use App\Publishing\MetaBlobAssembler;
use App\SiloCreator\PillarFactory;
use App\Standard\StandardKit;
use Illuminate\Console\Command;

/**
 * Diagnose / repair page Content that has NO wireframe kit linked.
 *
 * A page materialized before its wireframe kit existed in the DB (the classic case: the
 * standard-page kits weren't seeded on prod yet — see {@see SyncKitsCommand}) is created
 * with `wireframe_kit_id = null` and, because materialization is idempotent by content_id,
 * is never re-resolved. At publish, a null kit means {@see MetaBlobAssembler}
 * resolves no schema, so its `nativeBody()` returns `[]` — the plugin then writes NO
 * `_elementor_data` and the page renders as the flat dynamic-template fallback instead of
 * the kit's native Elementor blocks (the "composes fine but publishes as a flat blob"
 * symptom). The drafted slot_payload is unaffected, which is why the copy looks right.
 *
 * This command relinks each kit-less page to the kit it SHOULD have (standard pages by
 * `standard_type`, every other page by `page_type` — exactly the materializer's logic). It
 * is read-only by default (a report); `--apply` writes the kit id/version. It only ever
 * FILLS a null link — it never overwrites a page that already has a kit. After `--apply`,
 * re-publish the site so the native body is pushed.
 */
class RelinkPageKitsCommand extends Command
{
    protected $signature = 'launchpad:relink-page-kits {site : Site id (ULID) or exact brand_name} {--apply : Write the resolved kit id (default: dry-run report only)}';

    protected $description = 'Relink page Content that has no wireframe kit (publishes flat instead of native Elementor blocks). Dry-run unless --apply.';

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
                $this->line("  - {$page->slug} (page_type={$page->page_type->value}, standard_type={$std}) — no kit resolves yet (its composer may not have shipped)");

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
                $this->line("  • {$page->slug} → would link {$kit->name} v{$kit->version} (currently flat)");
            }
        }

        $this->newLine();
        if ($missing === 0 && $unresolvable === 0) {
            $this->info('All pages already carry a wireframe kit — none publish flat for this reason.');
        } elseif ($apply) {
            $this->info("Linked {$linked} kit-less page(s). Re-publish the site so the native Elementor body is pushed.");
        } else {
            $this->warn("{$missing} page(s) have no kit and publish flat. Re-run with --apply to relink, then re-publish.");
        }
        if ($unresolvable > 0) {
            $this->line("{$unresolvable} page(s) have no resolvable kit yet (held until their composer ships) — left untouched.");
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
