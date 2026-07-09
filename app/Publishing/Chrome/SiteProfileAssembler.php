<?php

namespace App\Publishing\Chrome;

use App\Branding\LogoHeaderTone;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
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

        // The chrome phone resolves the same way every surface does — the primary location's number,
        // else the site business phone — so a guided-onboarded tenant's header never ships empty.
        $phone = trim((string) $this->contact->phone($site));
        $address = $location !== null ? trim((string) $location->address) : '';

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
            'services' => $this->services($site, $home),
            'areas' => $this->areas($site),
            'company' => $this->company($site, $home),
            // The header main menu: the company pages + Areas We Serve (a top-level destination, not a
            // footer afterthought). Legal pages stay OUT of the header — they live in the footer bar.
            'nav' => [...$this->company($site, $home), ...$this->pagesBySlug($site, $home, ['areas-we-serve', 'areas', 'service-areas'])],
            // Privacy / Terms for the footer bottom bar — only pages that actually exist.
            'legal_links' => $this->pagesBySlug($site, $home, ['privacy-policy', 'privacy', 'terms-of-service', 'terms']),
        ];
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
     * The header background that best shows the logo — 'dark' or 'light', derived at logo intake
     * ({@see LogoHeaderTone}). Defaults to 'dark' (no logo / no signal) — the platform's standard
     * branded header — so a logo only ever flips the bar to light when it genuinely needs it. The
     * plugin renders the matching `lp-tone-{tone}` class.
     */
    private function headerTone(Site $site): string
    {
        $tone = trim((string) ($this->logoSet($site)['header_tone'] ?? ''));

        return $tone === 'light' ? 'light' : 'dark';
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
     * The site's service / hub pages as nav links — real internal links only.
     *
     * @return list<array{label: string, url: string}>
     */
    private function services(Site $site, string $home): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('page_type', [PageType::Service->value, PageType::Hub->value])
            ->whereNotNull('slug')
            ->orderBy('created_at')
            ->limit(6)
            ->get();

        return $this->links($pages, $home);
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
