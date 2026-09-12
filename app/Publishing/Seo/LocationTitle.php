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

        return SeoTitle::normalize($this->leadWithPlace($content, $city, $state), $content->source_name);
    }

    /**
     * The deterministic "{Trade} in {City}, {ST}" for an UN-ANCHORED hub/landing page, composed from the
     * page's OWN "{City}, {ST}" identity (its LocationSubject subject), returned WITH the resolved city so
     * the caller can prefer it only over a stored title that fails to name that place. This rescues a
     * storefront-hub/market landing whose pin is unresolvable and whose stored SEO title was clobbered to a
     * geography-less phrase.
     *
     * Null when the page is a TOWN (an un-anchored town's title may be a drafter hallucination of the WRONG
     * town — never composed from; that is #823's caution, preserved) or when no reliable "{City}, {ST}"
     * place is derivable (an arbitrary slug/title, so no place is ever invented).
     *
     * @return array{title: string, city: string}|null
     */
    public function fromIdentity(Content $content): ?array
    {
        $isTown = $content->location_id === null && $content->parent_location_id !== null;
        if ($isTown) {
            return null;
        }

        ['city' => $city, 'state' => $state] = $this->subject->resolve($content);
        if ($city === '' || $state === '') {
            return null;
        }

        $title = SeoTitle::normalize($this->leadWithPlace($content, $city, $state), $content->source_name);

        return ['title' => $title, 'city' => $city];
    }

    /**
     * Compose the page portion so the TOWN is never the part the length cap sacrifices. The town is the
     * whole point of a location title, but SeoTitle::truncate cuts from the END — and with a long or
     * comma-joined trade (e.g. SPG's "sump & sewage pump service and replacement, basement waterproofing")
     * "{Trade} in {Town}, {ST}" runs past the cap and truncation drops the geography (it even cuts at the
     * comma INSIDE the trade). So build the fullest town-preserving form that fits:
     *   1. "{Trade} in {Town}, {ST}" when it fits;
     *   2. else the trade's first comma-clause + place, when THAT fits (drops a trailing ", second trade");
     *   3. else the place alone ("{Town}, {ST}") — always short, always keeps the town.
     * A brand suffix is still appended downstream by withBrand; a very long trade may push the whole title
     * past the cap, but the town is present and it is surfaced (not mangled) by report-title-lengths.
     */
    private function leadWithPlace(Content $content, string $city, string $state): string
    {
        $place = $state !== '' ? $city.', '.$state : $city;
        $trade = $this->trade($content);
        if ($trade === '') {
            return $place;
        }

        if (mb_strlen($trade.' in '.$place) <= SeoTitle::MAX_LENGTH) {
            return $trade.' in '.$place;
        }

        $parts = preg_split('/\s*,\s*/', $trade);
        $firstClause = is_array($parts) ? trim($parts[0]) : '';
        if ($firstClause !== '' && $firstClause !== $trade && mb_strlen($firstClause.' in '.$place) <= SeoTitle::MAX_LENGTH) {
            return $firstClause.' in '.$place;
        }

        return $place;
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
