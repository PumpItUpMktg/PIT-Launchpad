<?php

namespace App\Publishing;

use App\Integrations\Wordpress\WordpressClientFactory;
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
        ]);

        if (isset($response['wp_category_id'])) {
            $silo->forceFill(['wp_category_id' => (int) $response['wp_category_id']])->save();
        }

        return $response;
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
