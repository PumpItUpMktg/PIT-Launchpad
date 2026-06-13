<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\SiloType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\SiloCreator\PillarFactory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Repair a §4 page-pillar that was FLIPPED to kind=post — the failure mode where a
 * pillar stub (kind=page) was drafted through the Candidates "Generate post" lane,
 * which hard-codes kind=Post and published it through the blog template.
 *
 * Resets the row to kind=page + its silo-derived page_type (service_pillar→service,
 * topical→pillar), re-pins the matching wireframe kit (the same one a fresh pillar
 * gets), and returns it to candidate with the post-draft artifacts cleared — so it
 * can re-generate correctly via Pages → "Generate page". Idempotent: a pillar that
 * is already kind=page is left untouched (a legitimately published page is never
 * reset). The lane leak itself is fixed in code; this repairs rows already corrupted.
 */
class RepairPagePillarCommand extends Command
{
    protected $signature = 'launchpad:repair-page-pillar {content? : a flipped pillar Content id} {--site= : repair every flipped page-pillar of this site id}';

    protected $description = 'Reset §4 page-pillars flipped to kind=post back to kind=page (+ silo page_type), re-pin the kit, return to candidate. Idempotent; one row or a whole --site.';

    public function handle(): int
    {
        $silos = $this->targetSilos();
        if ($silos === null) {
            return self::FAILURE;
        }

        $repaired = 0;
        foreach ($silos as $silo) {
            $pillar = $silo->pillar_content_id !== null
                ? Content::withoutGlobalScope(SiteScope::class)->find($silo->pillar_content_id)
                : null;

            if ($pillar === null) {
                continue;
            }

            if ($pillar->kind !== ContentKind::Post) {
                $this->line("• {$pillar->id} \"{$pillar->title}\" — already kind={$pillar->kind->value}; skipped.");

                continue;
            }

            $this->repair($pillar, $silo);
            $repaired++;
        }

        $this->info($repaired === 0
            ? 'No flipped page-pillars found — nothing to repair.'
            : "Repaired {$repaired} page-pillar(s). Re-generate via Pages → \"Generate page\".");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Silo>|null
     */
    private function targetSilos(): ?Collection
    {
        $contentId = $this->argument('content');
        $siteId = $this->option('site');

        if ($contentId !== null) {
            $silos = Silo::withoutGlobalScope(SiteScope::class)->where('pillar_content_id', $contentId)->get();
            if ($silos->isEmpty()) {
                $this->error("No silo has pillar_content_id = [{$contentId}] — it is not a pillar (or the id is wrong).");

                return null;
            }

            return $silos;
        }

        if ($siteId !== null) {
            return Silo::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->whereNotNull('pillar_content_id')
                ->get();
        }

        $this->error('Provide a {content} id or --site=.');

        return null;
    }

    private function repair(Content $pillar, Silo $silo): void
    {
        // A service pillar renders the service kit; a topical pillar a hub/pillar kit
        // (none seeded yet → null, surfaced). Mirrors ManualSiloCreator's mapping.
        $pageType = $silo->type === SiloType::ServicePillar ? PageType::Service : PageType::Pillar;
        $kit = PillarFactory::resolveKit($pageType, (string) $pillar->site_id);

        $meta = $pillar->meta ?? [];
        unset($meta['generating_at'], $meta['draft_error'], $meta['draft_failure'], $meta['draft_failed_at']);

        $hadWpPost = $pillar->wp_post_id;

        $pillar->forceFill([
            'kind' => ContentKind::Page,
            'page_type' => $pageType,
            'status' => ContentStatus::Candidate,
            'wireframe_kit_id' => $kit?->id,
            'wireframe_kit_version' => $kit?->version,
            'body' => null,
            'slot_payload' => null,
            'wp_post_id' => null,
            'published_at' => null,
            'last_publish_error' => null,
            'meta' => $meta,
        ])->save();

        $this->line("• {$pillar->id} \"{$pillar->title}\" → kind=page, page_type={$pageType->value}, kit=".($kit !== null ? $kit->id : 'none').', status=candidate.');

        if ($hadWpPost !== null) {
            $this->warn("  ↳ a live WordPress post remains (wp_post_id={$hadWpPost}); trash it in WordPress so it is not a duplicate.");
        }
    }
}
