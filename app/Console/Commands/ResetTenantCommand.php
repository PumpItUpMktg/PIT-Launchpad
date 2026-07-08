<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\SiteStatus;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\BuildPage;
use App\Models\Competitor;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Conversion;
use App\Models\ConversionConfig;
use App\Models\ConversionSyncState;
use App\Models\CoverageArea;
use App\Models\Goal;
use App\Models\Keyword;
use App\Models\LaunchRun;
use App\Models\Location;
use App\Models\Market;
use App\Models\MediaAsset;
use App\Models\Offer;
use App\Models\PositionSnapshot;
use App\Models\ProofItem;
use App\Models\Redirect;
use App\Models\RefreshEvent;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SetupState;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\SiloLink;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use App\Models\SiteTemplateMapping;
use App\Models\Source;
use App\Models\Spoke;
use App\Models\VoiceProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TEST-HARNESS tooling (not product): rewind a tenant's Launchpad onboarding to zero for a clean
 * re-test, while preserving the expensive-to-rebuild WordPress layer. The boundary is wizard step 2
 * (Connect WordPress): {@see --keep-wordpress} keeps that step's output (the WP connection); the wipe
 * clears everything downstream (pages, structure, territory, services, intake, wizard progress) and
 * flips the site back to onboarding.
 *
 * Two iron laws drive the order:
 *  1. WP cleanup runs BEFORE the Launchpad wipe — the Content rows hold the WP post ids of what
 *     Launchpad published, so we read them, force-delete on WP, THEN wipe. Wipe first and the post
 *     ids are gone → orphaned WP pages + permanently locked permalinks.
 *  2. Force-delete on WP (`force=true`), never trash — a trashed post still reserves its slug, which
 *     re-slugs the next run to `-2` and defeats the cleanup. Scoped to Launchpad-tracked post ids only.
 *
 * Guards: a named confirmation, and a test-only gate that refuses a `live` (handed-over) tenant.
 */
class ResetTenantCommand extends Command
{
    protected $signature = 'launchpad:reset-tenant
        {site : Site id or brand name}
        {--keep-wordpress : preserve the WP connection (URL / app password / verified status)}
        {--keep-brand : preserve the VoiceKit voice profile + brand intake (SiteNarrative/SiteBranding)}
        {--force : skip the confirmation prompt}
        {--dry-run : report what would change without touching WordPress or the database}';

    protected $description = 'Test-harness: rewind a tenant\'s Launchpad onboarding to zero (force-deleting its published WP pages first), keeping the WP connection (--keep-wordpress) and optionally brand (--keep-brand).';

    public function handle(WordpressClientFactory $wordpress): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        // Test-only gate: a live (client-handed-over) tenant is production — never reset it.
        // (There is no dedicated client flag yet; SiteStatus::Live is the handover milestone.)
        if ($site->status === SiteStatus::Live) {
            $this->error("Refusing to reset '{$site->brand_name}' — it is LIVE (handed to a client). Test-reset is for non-live tenants only.");

            return self::FAILURE;
        }

        $keepWordpress = (bool) $this->option('keep-wordpress');
        $keepBrand = (bool) $this->option('keep-brand');
        $dryRun = (bool) $this->option('dry-run');

        $published = $this->publishedPages($site->id);

        if (! $this->confirmReset($site, $keepWordpress, $keepBrand, count($published), $dryRun)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        // ── 1. WP cleanup FIRST (read post ids from Content, force-delete on WP, free the slugs) ──
        $wpDeleted = [];
        $wpFailed = [];
        if (! $dryRun && $published !== []) {
            $client = null;
            try {
                $client = $wordpress->forSite($site);
            } catch (Throwable $e) {
                $this->warn('No usable WordPress connection — skipping WP cleanup ('.$e->getMessage().').');
            }

            foreach ($published as $page) {
                if ($client === null) {
                    $wpFailed[] = $page['slug'];

                    continue;
                }
                try {
                    // Delete via the companion plugin (by ULID), not the core WP route — see WordpressClient.
                    $client->deleteContent($page['content_id']);
                    $wpDeleted[] = $page['slug'];
                } catch (Throwable $e) {
                    $wpFailed[] = $page['slug'];
                }
            }
        }

        // ── 2. Launchpad wipe (counts captured for the report; skipped on --dry-run) ──
        $counts = $this->countCategories($site->id, $keepBrand);
        if (! $dryRun) {
            $this->wipe($site, $keepWordpress, $keepBrand);
        }

        $this->report($site, $keepWordpress, $keepBrand, $counts, $published, $wpDeleted, $wpFailed, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Launchpad-published pages/posts (those carrying a WP post id) — the cleanup targets, read from
     * Content BEFORE the wipe. `kind` maps to the core WP post type (page vs post).
     *
     * @return list<array{content_id: string, wp_post_id: int, slug: string, type: 'pages'|'posts'}>
     */
    private function publishedPages(string $siteId): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->whereNotNull('wp_post_id')
            ->get(['id', 'wp_post_id', 'slug', 'kind'])
            ->map(fn (Content $c): array => [
                'content_id' => (string) $c->id,
                'wp_post_id' => (int) $c->wp_post_id,
                'slug' => '/'.ltrim((string) $c->slug, '/'),
                'type' => $c->kind === ContentKind::Page ? 'pages' : 'posts',
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function countCategories(string $siteId, bool $keepBrand): array
    {
        $forSite = fn (string $class) => $class::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId);

        $counts = [
            'pages' => (clone $forSite(Content::class))->where('kind', ContentKind::Page->value)->count(),
            'posts' => (clone $forSite(Content::class))->where('kind', ContentKind::Post->value)->count(),
            'silos' => $forSite(Silo::class)->count(),
            'markets' => $forSite(Market::class)->count(),
            'services' => $forSite(Service::class)->count(),
            'keywords' => $forSite(Keyword::class)->count(),
        ];

        if (! $keepBrand) {
            $counts['voice_profiles'] = $forSite(VoiceProfile::class)->count();
            $counts['narratives'] = $forSite(SiteNarrative::class)->count();
        }

        return $counts;
    }

    /** Wipe all site-scoped Launchpad state, honouring the keep flags. No restrict FKs exist, so the
     *  per-table deletes (children cascade from parents / from the site FK) are order-independent;
     *  the four soft-deleted models are force-deleted for a true wipe. */
    private function wipe(Site $site, bool $keepWordpress, bool $keepBrand): void
    {
        $id = $site->id;

        DB::transaction(function () use ($site, $id, $keepWordpress, $keepBrand): void {
            // Soft-deleted models → forceDelete (and cascade their children: versions, render jobs,
            // pivots, snapshots, spokes-via-blueprint, …).
            foreach ([Content::class, Silo::class, ProofItem::class, MediaAsset::class] as $soft) {
                $soft::withoutGlobalScope(SiteScope::class)->where('site_id', $id)->forceDelete();
            }

            // Remaining site-scoped models (hard delete; pivots/children cascade at the DB).
            $models = [
                SiloBlueprint::class, SiloLink::class, Spoke::class,
                Service::class, Market::class, Keyword::class,
                Offer::class, Source::class, CoverageArea::class,
                Competitor::class, Redirect::class, LaunchRun::class,
                Goal::class, Location::class, Conversion::class,
                ConversionConfig::class, ConversionSyncState::class,
                PositionSnapshot::class, RenderJob::class, RefreshEvent::class,
                BuildPage::class, SiteTemplateMapping::class,
            ];
            foreach ($models as $model) {
                $model::withoutGlobalScope(SiteScope::class)->where('site_id', $id)->delete();
            }

            // Tables that carry site_id but have no cascade source (must be cleared explicitly).
            foreach (['content_edits', 'page_configs', 'arrange_flags', 'onboarding_states'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->where('site_id', $id)->delete();
                }
            }

            // Wizard progress → gone (re-created fresh at step 1 on the next visit).
            SetupState::withoutGlobalScope(SiteScope::class)->where('site_id', $id)->delete();

            if (! $keepBrand) {
                foreach ([VoiceProfile::class, SiteNarrative::class, SiteBranding::class] as $brand) {
                    $brand::withoutGlobalScope(SiteScope::class)->where('site_id', $id)->delete();
                }
            }

            if (! $keepWordpress) {
                Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $id)->delete();
            }

            // The site record persists as the anchor; its wizard-collected business fields clear and
            // the lifecycle flag returns to onboarding (so the overview shows it "in setup" again).
            // brand_name is NOT NULL — kept as the label; domain_url is part of the WP layer.
            $site->forceFill([
                'legal_name' => null,
                'dba' => null,
                'tagline' => null,
                'slug_conventions' => null,
                'domain_url' => $keepWordpress ? $site->domain_url : null,
                'status' => SiteStatus::Onboarding,
            ])->save();
        });
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<array{wp_post_id: int, slug: string, type: string}>  $published
     * @param  list<string>  $wpDeleted
     * @param  list<string>  $wpFailed
     */
    private function report(Site $site, bool $keepWordpress, bool $keepBrand, array $counts, array $published, array $wpDeleted, array $wpFailed, bool $dryRun): void
    {
        $prefix = $dryRun ? '[dry-run] would reset' : 'Reset';
        $wiped = "{$counts['pages']} pages · {$counts['posts']} posts · {$counts['silos']} silos · {$counts['markets']} markets · {$counts['services']} services · {$counts['keywords']} keywords · wizard progress";

        $this->newLine();
        $this->info("{$prefix} '{$site->brand_name}' ({$site->id}) → onboarding.");
        $this->line("  wiped: {$wiped}");
        $this->line('  WP connection: '.($keepWordpress ? 'kept' : 'removed'));
        $this->line('  brand (voice + intake): '.($keepBrand ? 'kept' : 'wiped'));

        if ($dryRun) {
            $this->line('  WP pages to force-delete: '.(count($published) > 0 ? implode(', ', array_column($published, 'slug')) : 'none'));

            return;
        }

        if ($wpDeleted !== []) {
            $this->line('  force-deleted '.count($wpDeleted).' published WP page(s): '.implode(', ', $wpDeleted));
        }
        if ($wpFailed !== []) {
            $this->warn('  ⚠ '.count($wpFailed).' WP page(s) NOT deleted (slug may still be reserved): '.implode(', ', $wpFailed));
        }
        if ($published === []) {
            $this->line('  no published WP pages to clean up.');
        }
    }

    private function confirmReset(Site $site, bool $keepWordpress, bool $keepBrand, int $publishedCount, bool $dryRun): bool
    {
        if ($this->option('force') || $dryRun) {
            return true;
        }

        $keeps = ['the site record', $keepWordpress ? 'the WP connection' : null, $keepBrand ? 'brand (voice + intake)' : null];
        $keeps = implode(', ', array_filter($keeps));

        $this->warn("This rewinds '{$site->brand_name}' ({$site->id}) to onboarding:");
        $this->line("  WIPES pages / structure / territory / services / intake / wizard progress, and force-deletes {$publishedCount} published WP page(s).");
        $this->line("  KEEPS {$keeps}.");

        return $this->confirm('Proceed?');
    }

    private function resolveSite(string $arg): ?Site
    {
        return Site::query()->find($arg)
            ?? Site::query()->where('brand_name', $arg)->first();
    }
}
