<?php

namespace App\Operator\Controls;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteTemplateMapping;
use App\Models\WireframeKit;
use Illuminate\Support\Collection;

/**
 * §7b(c) controls — the operator's per-site kit→Elementor-template mapping. The
 * inventory is fetched LIVE from the site (so the operator maps against the
 * templates that actually exist there); the chosen mapping is stored engine-side,
 * versioned, and portfolio-visible. The §2 push reads {@see resolve()} to stamp
 * the resolved template on the /content blob — an explicit mapping wins over the
 * kit's elementor_template_ref suggestion.
 *
 * Operator/portfolio reads cross tenants, so every query drops the §1 SiteScope
 * and filters by explicit site_id.
 */
class TemplateMapping
{
    public function __construct(
        private readonly WordpressClientFactory $factory,
    ) {}

    /**
     * The site's live Elementor saved-template inventory (id/title/slug/type/
     * modified/preview_url/thumbnail). Throws WordpressException when the site is
     * unreachable / has no WP connection — the surface presents that, never a
     * stale guess.
     *
     * @return list<array<string, mixed>>
     */
    public function inventory(Site $site): array
    {
        return $this->factory->forSite($site)->templates();
    }

    /**
     * The kits "pushed" for this site — the distinct kits its page Content
     * references — each with its version and its `elementor_template_ref` default
     * SUGGESTION (which an explicit mapping overrides). These are the rows the
     * operator maps; a site with no page content yet maps nothing.
     *
     * @return list<array{kit: string, version: int|null, suggestion: string|null}>
     */
    public function kits(Site $site): array
    {
        $kitIds = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('wireframe_kit_id')
            ->distinct()
            ->pluck('wireframe_kit_id');

        return WireframeKit::query()
            ->whereIn('id', $kitIds)
            ->get()
            ->map(fn (WireframeKit $kit): array => [
                'kit' => (string) $kit->name,
                'version' => $kit->version,
                'suggestion' => $kit->elementor_template_ref,
            ])
            ->unique('kit')
            ->values()
            ->all();
    }

    /**
     * The site's current mappings, keyed by kit.
     *
     * @return Collection<string, SiteTemplateMapping>
     */
    public function current(Site $site): Collection
    {
        return SiteTemplateMapping::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->keyBy('kit');
    }

    /**
     * The mapping for a (site, kit), or null when none is set.
     */
    public function resolve(Site $site, string $kit): ?SiteTemplateMapping
    {
        return SiteTemplateMapping::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kit', $kit)
            ->first();
    }

    /**
     * Set (or remap) a kit's template. One current row per (site, kit); `version`
     * increments only when the target template actually changes, so a no-op save
     * doesn't inflate the history.
     */
    public function map(Site $site, string $kit, int $templateId, ?string $title = null): SiteTemplateMapping
    {
        $existing = $this->resolve($site, $kit);

        if ($existing !== null) {
            $existing->fill([
                'template_id' => $templateId,
                'template_title' => $title,
                'version' => $existing->template_id === $templateId ? $existing->version : $existing->version + 1,
            ])->save();

            return $existing;
        }

        return SiteTemplateMapping::create([
            'site_id' => $site->id,
            'kit' => $kit,
            'template_id' => $templateId,
            'template_title' => $title,
            'version' => 1,
        ]);
    }

    /**
     * Apply a batch of operator selections — [kit => template_id|null]. A null
     * selection clears the kit's mapping (back to its elementor_template_ref
     * suggestion); a non-null one maps/remaps it. `$titles` optionally supplies a
     * display-title cache per template id. Returns the number of selections acted
     * on. The single entry point the operator surface calls.
     *
     * @param  array<string, int|null>  $selections
     * @param  array<int, string>  $titles
     */
    public function applyMappings(Site $site, array $selections, array $titles = []): int
    {
        $applied = 0;

        foreach ($selections as $kit => $templateId) {
            if ($templateId === null || $templateId === 0) {
                if ($this->unmap($site, $kit)) {
                    $applied++;
                }

                continue;
            }

            $this->map($site, $kit, $templateId, $titles[$templateId] ?? null);
            $applied++;
        }

        return $applied;
    }

    /**
     * Clear a kit's mapping (fall back to the kit's elementor_template_ref
     * suggestion). Returns true when a row was removed.
     */
    public function unmap(Site $site, string $kit): bool
    {
        $existing = $this->resolve($site, $kit);

        return $existing !== null && (bool) $existing->delete();
    }
}
