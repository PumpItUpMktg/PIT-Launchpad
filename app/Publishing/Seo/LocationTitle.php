<?php

namespace App\Publishing\Seo;

use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Publishing\Blocks\LocationSubject;
use App\Support\SeoTitle;

/**
 * The DETERMINISTIC location-page title — "{Trade} in {Town}, {ST}" — composed from the page's authoritative
 * structured subject (LocationSubject) and the site's pillar-service head term, NOT from the drafter-authored
 * (sometimes hallucinated) stored title. This is the PAGE PORTION only; the brand suffix + length guard are
 * applied downstream by MetaBlobAssembler::withBrand(), so the value stays brand-free and obeys the shared
 * title rules via SeoTitle::normalize.
 *
 * Returns null when the page has no authoritative subject (an un-anchored town page) — the caller then
 * degrades to the stored-title behaviour rather than laundering a bad title through an ambiguous name match.
 */
final class LocationTitle
{
    public function __construct(private readonly LocationSubject $subject) {}

    public function compose(Content $content): ?string
    {
        ['city' => $city, 'state' => $state, 'anchored' => $anchored] = $this->subject->resolve($content);

        if (! $anchored || $city === '') {
            return null;
        }

        $place = $state !== '' ? $city.', '.$state : $city;
        $trade = $this->trade($content);
        $title = $trade !== '' ? $trade.' in '.$place : $place;

        return SeoTitle::normalize($title, $content->source_name);
    }

    /**
     * The service label the title leads with, in preference order: the page's own primary service, the
     * site's pillar service (both real, proper-cased Service names — mirrors CityKeywordTracker's head term),
     * and finally the site's captured trade noun (SiloBlueprint.trade — the same source the location H1
     * formula uses, so title and H1 agree; capitalised to read as a title term). '' only when the site has
     * neither a service nor a captured trade, and the title then names the place alone.
     */
    private function trade(Content $content): string
    {
        if ($content->primary_service_id !== null) {
            $service = Service::withoutGlobalScope(SiteScope::class)->find($content->primary_service_id);
            if ($service !== null && trim((string) $service->name) !== '') {
                return trim((string) $service->name);
            }
        }

        $pillar = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('silo_role', ServiceSiloRole::Pillar->value)
            ->orderBy('name')
            ->first(['name']);
        if ($pillar !== null && trim((string) $pillar->name) !== '') {
            return trim((string) $pillar->name);
        }

        // Last resort: the owner-interview trade noun the H1 formula falls back to, so the title still leads
        // with the service rather than the bare town on a tenant with no pillar service designated.
        $blueprintTrade = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->value('trade');

        return is_string($blueprintTrade) && trim($blueprintTrade) !== '' ? ucfirst(trim($blueprintTrade)) : '';
    }
}
