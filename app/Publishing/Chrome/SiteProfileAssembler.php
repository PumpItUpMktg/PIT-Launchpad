<?php

namespace App\Publishing\Chrome;

use App\Branding\LogoHeaderTone;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\SiteContact;
use Illuminate\Support\Collection;

/**
 * Builds the per-tenant site PROFILE the companion plugin's universal header/footer chrome renders
 * (brand + NAP + navigation) — the block-theme template parts can't express this statically. Pure and
 * testable: a Site in, the profile array out; the push is a thin transport on top (see
 * {@see SiteProfilePublisher}). Sourced entirely from real §1 data — links point
 * only to pages that exist, so the chrome never advertises a page that isn't there.
 */
final class SiteProfileAssembler
{
    /** The most services the header bar shows (importance-ranked); the rest live on the hub + footer. */
    private const HEADER_SERVICE_LIMIT = 8;

    /** The pages eligible for the header MAIN menu — company pages + the Areas We Serve page. */
    public const MAIN_NAV_SLUGS = ['about', 'about-us', 'faq', 'contact', 'why-choose-us', 'why-us', 'areas-we-serve', 'areas', 'service-areas'];

    public function __construct(private readonly SiteContact $contact) {}

    /**
     * @return array<string, mixed>
     */
    public function assemble(Site $site): array
    {
        $home = $this->homeBase($site);
        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->first();

        // The chrome NAP is the SITE-WIDE corporate one from the business intake — the main business
        // phone (sites.phone) and corporate address — never a physical location's, so a multi-location
        // tenant's header/footer shows the corporate line, not whichever location happens to be first.
        // Both fall back to the earliest Location only when no corporate value was captured (legacy).
        $phone = trim((string) $this->contact->phone($site));
        $address = trim((string) $this->contact->address($site));

        $services = $this->services($site, $home);
        // Never list the same page twice: a page an operator pinned into the header (services) is
        // dropped from the main-nav + company groups so it can't appear in two menus.
        $serviceUrls = array_column($services, 'url');
        $company = array_values(array_filter(
            $this->company($site, $home),
            fn (array $link): bool => ! in_array($link['url'], $serviceUrls, true),
        ));
        $mainNav = array_values(array_filter(
            $this->mainNav($site, $home),
            fn (array $link): bool => ! in_array($link['url'], $serviceUrls, true),
        ));

        return [
            'brand_name' => (string) $site->brand_name,
            'logo_url' => $this->logoUrl($site),
            'header_tone' => $this->headerTone($site),
            'tagline' => $this->tagline($site),
            'phone' => $phone,
            'phone_tel' => $this->tel($phone),
            'emergency' => (bool) $site->offers_emergency,
            'address' => $address,
            'hours' => $this->hours($location, (bool) $site->offers_emergency),
            'legal' => '',
            'services' => $services,
            'areas' => $this->areas($site),
            'company' => $company,
            // The header main menu: the company pages + Areas We Serve (a top-level destination, not a
            // footer afterthought), in the operator's Header-menu-builder order. Legal pages stay OUT of
            // the header — they live in the footer bar.
            'nav' => $mainNav,
            // Privacy / Terms for the footer bottom bar — only pages that actually exist.
            'legal_links' => $this->pagesBySlug($site, $home, ['privacy-policy', 'privacy', 'terms-of-service', 'terms']),
            // Severe-weather banner config — coords + on/off; the plugin fetches the live forecast itself.
            'alert' => $this->weatherAlert($site, $location, $home),
        ];
    }

    /**
     * The severe-weather-alert config the companion plugin renders a banner from. Enabled only for a
     * rain-relevant trade (keyword-matched) WITH real coordinates — the site's own location first, else
     * a priority market. The plugin owns the live forecast + threshold; this just hands it where to look
     * and where the CTA points. A non-rain trade or no coords → disabled (the banner never shows).
     *
     * @return array{enabled: bool, lat: float|null, lng: float|null, noun: string, cta_label: string, cta_url: string}
     */
    private function weatherAlert(Site $site, ?Location $location, string $home): array
    {
        $trade = mb_strtolower($this->trade($site));
        $relevant = false;
        foreach ((array) config('launchpad.weather_alert.trades', []) as $keyword) {
            $keyword = mb_strtolower(trim((string) $keyword));
            if ($keyword !== '' && str_contains($trade, $keyword)) {
                $relevant = true;
                break;
            }
        }

        [$lat, $lng] = $this->alertCoords($site, $location);
        $contact = $this->pagesBySlug($site, $home, ['contact', 'contact-us']);

        return [
            'enabled' => $relevant && $lat !== null && $lng !== null,
            'lat' => $lat,
            'lng' => $lng,
            'noun' => (string) config('launchpad.weather_alert.noun', 'sump pump'),
            'cta_label' => 'Book a check',
            'cta_url' => $contact[0]['url'] ?? $home,
        ];
    }

    /**
     * The coordinates to forecast against: the business's own location, else the nearest thing we have —
     * a priority market's centroid. Null pair when neither carries geocoded coordinates.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function alertCoords(Site $site, ?Location $location): array
    {
        if ($location !== null && $location->lat !== null && $location->lng !== null) {
            return [(float) $location->lat, (float) $location->lng];
        }

        $market = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderByRaw('CASE WHEN tier = ? THEN 0 ELSE 1 END', ['priority'])
            ->first();

        return $market !== null ? [(float) $market->lat, (float) $market->lng] : [null, null];
    }

    /** The site's captured trade (guided intake seed), or ''. */
    private function trade(Site $site): string
    {
        $trade = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->value('trade');

        return is_string($trade) ? trim($trade) : '';
    }

    /**
     * Existing pages matched by slug, as links — the chrome never advertises a page that isn't there.
     *
     * @param  list<string>  $slugs
     * @return list<array{label: string, url: string}>
     */
    private function pagesBySlug(Site $site, string $home, array $slugs): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('slug', $slugs)
            ->orderBy('created_at')
            ->get();

        return $this->links($pages, $home);
    }

    /** The uploaded logo's R2 URL (served like every other image, not from the WP media library). */
    private function logoUrl(Site $site): string
    {
        return trim((string) ($this->logoSet($site)['url'] ?? ''));
    }

    /**
     * The header background — 'dark' or 'light'. An explicit operator override wins outright
     * (`header_tone_override`); otherwise it's derived from the uploaded logo at intake
     * ({@see LogoHeaderTone}), defaulting to 'light' (no logo / no signal) — the clean white bar, which
     * is also the plugin's own render-time fallback, so a profile sync never repaints a no-signal
     * tenant's header. A logo only flips the bar to 'dark' when it genuinely reads better there. The
     * plugin renders the matching `lp-tone-{tone}` class.
     */
    private function headerTone(Site $site): string
    {
        $override = trim((string) ($site->header_tone_override ?? ''));
        if ($override === 'dark' || $override === 'light') {
            return $override;
        }

        $tone = trim((string) ($this->logoSet($site)['header_tone'] ?? ''));

        return $tone === 'dark' ? 'dark' : 'light';
    }

    /** @return array<string, mixed> */
    private function logoSet(Site $site): array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->first();

        return is_array($branding?->logo_set) ? $branding->logo_set : [];
    }

    /** The home-page hero eyebrow (trade · region) doubles as the brand tagline when present. */
    private function tagline(Site $site): string
    {
        $home = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Home->value)
            ->first();

        $slots = is_array($home?->slot_payload) ? $home->slot_payload : [];
        $area = $slots['service_area'] ?? '';

        return trim(is_array($area) ? (string) ($area[0] ?? '') : (string) $area);
    }

    /**
     * The header main menu links — real internal links only. TWO modes:
     *
     *  - OPERATOR-CURATED: if the site has any `nav_featured` page, the header shows exactly those, in
     *    the operator's manual `nav_order` (ascending, nulls last; importance then age break ties).
     *    Uncapped — the operator decides how many top-level items there are.
     *  - AUTOMATIC (no page featured): the service/hub pages ranked by IMPORTANCE (category hub first,
     *    then core/pillar, then supporting, then longtail guides), capped at {@see HEADER_SERVICE_LIMIT}.
     *    Every service still stays reachable from the hub ("Our services") page + the footer.
     *
     * @return list<array{label: string, url: string}>
     */
    private function services(Site $site, string $home): array
    {
        $featured = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('nav_featured', true)
            ->whereNotNull('slug')
            ->with('primaryService:id,silo_role')
            ->get();

        if ($featured->isNotEmpty()) {
            // Operator order first (nulls last), then importance, then age — a stable composite key.
            $pages = $featured
                ->sortBy(fn (Content $p): string => sprintf(
                    '%010d-%d-%015d',
                    $p->nav_order ?? 999999,
                    $this->serviceNavRank($p),
                    $p->created_at->getTimestamp(),
                ))
                ->values();

            return $this->links($pages, $home);
        }

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('page_type', [PageType::Service->value, PageType::Hub->value])
            ->whereNotNull('slug')
            ->with('primaryService:id,silo_role')
            ->get()
            // rank ASC, then oldest-first — a stable composite key ("rank-timestamp").
            ->sortBy(fn (Content $p): string => sprintf('%d-%015d', $this->serviceNavRank($p), $p->created_at->getTimestamp()))
            ->take(self::HEADER_SERVICE_LIMIT)
            ->values();

        return $this->links($pages, $home);
    }

    /** Header nav priority: category hub → core (pillar) service → supporting service → guide/other. */
    private function serviceNavRank(Content $page): int
    {
        if ($page->page_type === PageType::Hub) {
            return 0;
        }

        return match ($page->primaryService?->silo_role) {
            ServiceSiloRole::Pillar => 1,
            ServiceSiloRole::Supporting => 2,
            default => 3, // no core service link (longtail / guide pages) sink last
        };
    }

    /**
     * Towns served, priority markets first. Plain labels (no per-town page assumed); a "+ more"
     * affordance when the list is truncated.
     *
     * @return list<array{label: string, url: string}>
     */
    private function areas(Site $site): array
    {
        $names = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByRaw('CASE WHEN tier = ? THEN 0 ELSE 1 END', ['priority'])
            ->orderBy('name')
            ->limit(9)
            ->pluck('name')
            ->map(fn ($n): string => trim((string) $n))
            ->filter(fn (string $n): bool => $n !== '')
            ->values()
            ->all();

        $shown = array_slice($names, 0, 6);
        $out = array_map(fn (string $n): array => ['label' => $n, 'url' => ''], $shown);
        if (count($names) > 6) {
            $out[] = ['label' => 'All areas →', 'url' => ''];
        }

        return $out;
    }

    /**
     * Informational pages (about / FAQ / contact / why-choose-us) — included only when the page
     * actually exists, matched by slug.
     *
     * @return list<array{label: string, url: string}>
     */
    private function company(Site $site, string $home): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('slug', ['about', 'about-us', 'faq', 'contact', 'why-choose-us', 'why-us'])
            ->orderBy('created_at')
            ->get();

        return $this->links($pages, $home);
    }

    /**
     * The header MAIN menu items — company pages + the Areas We Serve page — in the operator's chosen
     * order (`nav_order`, ascending, nulls last; then creation order). This is what the Header-menu
     * builder reorders; with no order set it falls back to creation order (the historical behaviour).
     *
     * @return list<array{label: string, url: string}>
     */
    private function mainNav(Site $site, string $home): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('slug', self::MAIN_NAV_SLUGS)
            ->orderByRaw('nav_order is null')
            ->orderBy('nav_order')
            ->orderBy('created_at')
            ->get();

        return $this->links($pages, $home);
    }

    /**
     * @param  Collection<int, Content>  $pages
     * @return list<array{label: string, url: string}>
     */
    private function links($pages, string $home): array
    {
        $out = [];
        foreach ($pages as $page) {
            $label = trim((string) $page->title);
            $slug = trim((string) $page->slug);
            if ($label === '' || $slug === '') {
                continue;
            }
            $out[] = ['label' => $label, 'url' => $home.ltrim($slug, '/')];
        }

        return $out;
    }

    /** A compact hours line — the weekday range (when present) plus the emergency note. */
    private function hours(?Location $location, bool $emergency): string
    {
        $parts = [];

        $hours = is_array($location?->hours) ? $location->hours : [];
        $mon = $hours['mon'] ?? null;
        if (is_array($mon) && trim((string) ($mon['open'] ?? '')) !== '' && trim((string) ($mon['close'] ?? '')) !== '') {
            $parts[] = 'Mon–Fri '.trim((string) $mon['open']).'–'.trim((string) $mon['close']);
        }

        if ($emergency) {
            $parts[] = '24/7 Emergency';
        }

        return implode(' · ', $parts);
    }

    private function homeBase(Site $site): string
    {
        return is_string($site->domain_url) && trim($site->domain_url) !== ''
            ? rtrim($site->domain_url, '/').'/'
            : '/';
    }

    private function tel(string $phone): string
    {
        if ($phone === '') {
            return '';
        }
        $digits = (string) preg_replace('/[^0-9+]/', '', $phone);

        return $digits !== '' ? 'tel:'.$digits : '';
    }
}
