<?php

namespace App\Publishing;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

/**
 * Pushes a silo's structure to /silo (keyed on the silo ULID) and stores the
 * WP category id the plugin maps back — the §4 wp_category_id the publish
 * pipeline was left to fill.
 */
class PublishSiloService
{
    public function __construct(
        private readonly WordpressClientFactory $wordpress,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function publish(Silo $silo): array
    {
        $site = Site::withoutGlobalScope(SiteScope::class)->findOrFail($silo->site_id);

        $response = $this->wordpress->forSite($site)->upsertSilo([
            'silo_id' => $silo->id,
            'name' => $silo->name,
            'parent_silo_id' => $silo->parent_silo_id,
            'description' => $this->pillarDescription($silo),
        ]);

        if (isset($response['wp_category_id'])) {
            $silo->forceFill(['wp_category_id' => (int) $response['wp_category_id']])->save();
        }

        return $response;
    }

    /**
     * The WP category description for a silo — the silo's pillar page meta description. Silo-level and
     * write-once (it rides the /silo taxonomy push, NOT each post publish), so every post in the silo
     * shares one authored blurb on the category archive. Empty when the silo has no pillar page yet;
     * the plugin then leaves the category description untouched rather than clearing it.
     */
    private function pillarDescription(Silo $silo): string
    {
        if ($silo->pillar_content_id === null) {
            return '';
        }

        $pillar = Content::withoutGlobalScope(SiteScope::class)->find($silo->pillar_content_id);
        $seo = is_array($pillar?->meta['seo'] ?? null) ? $pillar->meta['seo'] : [];

        return trim((string) ($seo['meta_description'] ?? ''));
    }

    /**
     * Push every silo of a site to its WP category, roots-first so a child's parent mapping is clean.
     * Idempotent by ULID — safe to re-run (projected at Finalize, re-pushed as the go-live backstop).
     * Returns the number of silos pushed.
     */
    public function publishSite(Site $site): int
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByRaw('parent_silo_id is not null') // roots (null parent) first
            ->orderBy('created_at')
            ->get();

        foreach ($silos as $silo) {
            $this->publish($silo);
        }

        return $silos->count();
    }
}
