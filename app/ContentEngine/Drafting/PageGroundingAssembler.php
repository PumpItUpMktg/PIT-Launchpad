<?php

namespace App\ContentEngine\Drafting;

use App\Enums\PageType;
use App\Models\Content;
use App\Models\Market;
use App\Models\Offer;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiteBranding;
use App\Models\WireframeKit;
use App\PageBuilder\Schema\KitSchema;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Builds the page grounding from the site's intake entities (services scoped to
 * the page's silo; offers/markets/proof/branding at the honest site level — the
 * "do not invent locality beyond what grounding supplies" rule). All reads bypass
 * the site global scope and filter on site_id explicitly, so assembly is safe on
 * the worker / console where CurrentSite isn't resolved. A page without a kit is
 * a setup error surfaced as a draft failure (the engine guard wraps the throw).
 */
class PageGroundingAssembler
{
    public function __construct(
        private readonly VoiceResolver $voice = new VoiceResolver,
    ) {}

    public function assemble(Content $page): PageGrounding
    {
        $siteId = (string) $page->site_id;
        $kit = $this->kit($page);
        $services = $this->services($siteId, $page->silo_id !== null ? (string) $page->silo_id : null);
        $voice = $this->voice->active($siteId);

        return new PageGrounding(
            kit: $kit,
            pageType: $page->page_type ?? PageType::Service,
            voiceProfile: $this->voice->toArray($voice),
            voiceProfileVersion: $voice !== null ? (int) $voice->version : 0,
            services: $services->map(fn (Service $s) => $this->serviceArray($s))->values()->all(),
            problems: $this->problems($services),
            offers: $this->offers($siteId),
            proof: $this->proof($siteId),
            markets: $this->markets($siteId),
            branding: $this->branding($siteId, $page),
            targetKeyword: $this->targetKeyword($page),
        );
    }

    private function kit(Content $page): KitSchema
    {
        $kit = $page->wireframe_kit_id !== null ? WireframeKit::find($page->wireframe_kit_id) : null;
        $schema = $kit?->schema();

        if ($schema === null) {
            throw new RuntimeException("Page [{$page->id}] has no resolvable wireframe kit — assign a kit before generating.");
        }

        return $schema;
    }

    /**
     * @return Collection<int, Service>
     */
    private function services(string $siteId, ?string $siloId): Collection
    {
        $query = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->with('problems');

        if ($siloId !== null) {
            $scoped = (clone $query)
                ->whereHas('silos', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->whereKey($siloId))
                ->get();

            if ($scoped->isNotEmpty()) {
                return $scoped;
            }
        }

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceArray(Service $service): array
    {
        return [
            'name' => $service->name,
            'description' => $service->description,
            'scope' => $service->scope,
            'pricing_posture' => $service->pricing_posture,
            'primary_cta_intent' => $service->primary_cta_intent,
            'geo_applicability' => $service->geo_applicability,
        ];
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return list<array<string, mixed>>
     */
    private function problems(Collection $services): array
    {
        $problems = [];
        foreach ($services as $service) {
            foreach ($service->problems as $problem) {
                $problems[] = ['phrase' => $problem->phrase, 'intent' => $problem->intent];
            }
        }

        return $problems;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function offers(string $siteId): array
    {
        return Offer::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get()
            ->map(fn (Offer $o) => ['name' => $o->name, 'terms' => $o->terms])
            ->values()
            ->all();
    }

    /**
     * The substantiated proof pool — the only business facts a page may assert.
     *
     * @return list<Claim>
     */
    private function proof(string $siteId): array
    {
        return ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('is_substantiated', true)
            ->get()
            ->map(fn (ProofItem $item) => Claim::fromProofItem($item))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function markets(string $siteId): array
    {
        return Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get()
            ->map(fn (Market $m) => [
                'name' => $m->name,
                'region' => $m->region,
                'neighborhoods' => $m->neighborhoods,
                'local_nuances' => $m->local_nuances,
                'is_covered' => (bool) $m->is_covered,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function branding(string $siteId, Content $page): array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->first();

        return [
            'brand_name' => $page->site?->brand_name,
            'entity_type' => $branding?->entity_type,
            'nap' => $branding?->canonical_nap,
        ];
    }

    private function targetKeyword(Content $page): ?string
    {
        return $page->targetKeyword()->withoutGlobalScope(SiteScope::class)->first()?->query;
    }
}
