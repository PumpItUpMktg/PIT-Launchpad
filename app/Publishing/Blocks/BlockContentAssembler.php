<?php

namespace App\Publishing\Blocks;

use App\Enums\ContentKind;
use App\Enums\MarketTier;
use App\Enums\ProofType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteNarrative;
use App\Publishing\MetaBlobAssembler;

/**
 * Resolves a page's real §1/§4 inputs and composes its `post_content` (core Gutenberg block markup) —
 * the wire between {@see MetaBlobAssembler} (which owns slot/image resolution) and the
 * {@see BlockPageComposer}. Kept separate from the composer so the composer stays pure/testable; kept
 * separate from MetaBlobAssembler so the Elementor path is untouched while the block path is proven.
 *
 * Returns null for page types whose block pattern hasn't shipped yet — the blob then simply carries no
 * `post_content` and the plugin (Layer 5) falls back to the existing render for those pages.
 */
final class BlockContentAssembler
{
    public function __construct(private readonly BlockPageComposer $composer) {}

    /**
     * @param  array<string, mixed>  $slots  the resolved slot_payload (from MetaBlobAssembler)
     * @param  array<string, array<string, mixed>>  $images  the resolved image map
     */
    public function compose(Content $content, array $slots, array $images): ?string
    {
        if ($content->kind !== ContentKind::Page) {
            return null;
        }

        // Home is the first shipped block pattern (the mockup's subject); others land next.
        if ($content->page_type?->value !== 'home') {
            return null;
        }

        $site = $this->site($content);
        $ctx = new PageContext(
            phoneDisplay: $this->phone($content),
            phoneTel: $this->tel($this->phone($content)),
            emergency: $site !== null && (bool) $site->offers_emergency,
        );

        [$areas, $areasMore] = $this->serviceAreas($content);

        return $this->composer->composeHome(
            slots: $slots,
            images: $images,
            serviceCards: $this->serviceCards($content, $site),
            ctx: $ctx,
            trustStats: $this->trustStats($content),
            credibilityBadges: $this->credibilityBadges($content),
            differentiators: $this->differentiators($content),
            testimonials: $this->testimonials($content),
            serviceAreas: $areas,
            serviceAreasMore: $areasMore,
        );
    }

    /**
     * Credibility badges — substantiated licenses / certs / awards / affiliations / warranties only.
     * Each item's short label becomes a badge; never fabricated, capped so the strip stays a strip.
     *
     * @return list<string>
     */
    private function credibilityBadges(Content $content): array
    {
        $types = [
            ProofType::License->value, ProofType::Cert->value, ProofType::Award->value,
            ProofType::Affiliation->value, ProofType::Warranty->value, ProofType::Guarantee->value,
        ];

        $items = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('is_substantiated', true)
            ->whereIn('type', $types)
            ->orderBy('created_at')
            ->limit(4)
            ->get();

        $badges = [];
        foreach ($items as $item) {
            $label = $this->payloadString($item, ['label', 'text']);
            if ($label !== '') {
                $badges[] = $label;
            }
        }

        return $badges;
    }

    /**
     * Why-Choose-Us differentiators from the site narrative — real captured value props only.
     *
     * @return list<array{title: string, description: string}>
     */
    private function differentiators(Content $content): array
    {
        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->first();

        $items = is_array($narrative?->differentiators) ? $narrative->differentiators : [];

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $out[] = ['title' => $title, 'description' => trim((string) ($item['description'] ?? ''))];
        }

        return array_slice($out, 0, 6);
    }

    /**
     * Testimonials — substantiated review / testimonial proof items only. Payload is freeform, so read
     * the conventional keys defensively; a quote is required, author/role/stars are optional.
     *
     * @return list<array{quote: string, author: string, role: string, stars: int}>
     */
    private function testimonials(Content $content): array
    {
        $items = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('is_substantiated', true)
            ->whereIn('type', [ProofType::Testimonial->value, ProofType::ReviewAggregate->value])
            ->orderBy('created_at')
            ->limit(3)
            ->get();

        $quotes = [];
        foreach ($items as $item) {
            $quote = $this->payloadString($item, ['quote', 'text', 'label']);
            if ($quote === '') {
                continue;
            }
            $payload = is_array($item->payload) ? $item->payload : [];
            $stars = (int) ($payload['stars'] ?? $payload['rating'] ?? 0);
            $quotes[] = [
                'quote' => $quote,
                'author' => $this->payloadString($item, ['author', 'name']),
                'role' => $this->payloadString($item, ['role', 'source', 'company']),
                'stars' => max(0, min(5, $stars)),
            ];
        }

        return $quotes;
    }

    /**
     * Service areas — the towns served, priority markets first. Returns the shown list (capped at 12)
     * plus an optional "+ more" affordance when more markets exist. Data-gated by the caller (empty →
     * no section). Geo lives only here and on location pages.
     *
     * @return array{0: list<string>, 1: ?string}
     */
    private function serviceAreas(Content $content): array
    {
        $names = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->orderByRaw('CASE WHEN tier = ? THEN 0 ELSE 1 END', [MarketTier::Priority->value])
            ->orderBy('name')
            ->limit(25)
            ->pluck('name')
            ->map(fn ($n): string => trim((string) $n))
            ->filter(fn (string $n): bool => $n !== '')
            ->values()
            ->all();

        $more = count($names) > 12 ? '+ more →' : null;

        return [array_slice($names, 0, 12), $more];
    }

    /**
     * First non-empty value among the given payload keys of a proof item (payload is freeform JSON).
     *
     * @param  list<string>  $keys
     */
    private function payloadString(ProofItem $item, array $keys): string
    {
        $payload = is_array($item->payload) ? $item->payload : [];
        foreach ($keys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** The site's top service/hub pages as grid cards — REAL internal links only (never invented). */
    private function serviceCards(Content $content, ?Site $site): array
    {
        $home = is_string($site?->domain_url) ? rtrim((string) $site->domain_url, '/').'/' : '/';

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('page_type', ['service', 'hub'])
            ->whereKeyNot($content->id)
            ->whereNotNull('slug')
            ->orderBy('created_at')
            ->limit(6)
            ->get();

        $cards = [];
        foreach ($pages as $page) {
            $title = trim((string) $page->title);
            if ($title === '') {
                continue;
            }
            $metaSeo = is_array($page->meta['seo'] ?? null) ? $page->meta['seo'] : [];
            $cards[] = [
                'title' => $title,
                'blurb' => trim((string) ($metaSeo['meta_description'] ?? '')),
                'url' => $home.ltrim((string) $page->slug, '/'),
            ];
        }

        return $cards;
    }

    /**
     * Hero trust stats from SUBSTANTIATED proof only — never fabricated. Each substantiated proof
     * item's short label becomes a stat; capped so the row stays a row.
     *
     * @return list<array{value: string, label: string}>
     */
    private function trustStats(Content $content): array
    {
        $items = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('is_substantiated', true)
            ->orderBy('created_at')
            ->limit(3)
            ->get();

        $stats = [];
        foreach ($items as $item) {
            $label = is_array($item->payload) ? trim((string) ($item->payload['label'] ?? '')) : '';
            if ($label !== '') {
                $stats[] = ['value' => $label, 'label' => ''];
            }
        }

        return $stats;
    }

    private function phone(Content $content): ?string
    {
        $phone = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->orderBy('created_at')
            ->value('phone');

        return is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    }

    private function tel(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = (string) preg_replace('/[^0-9+]/', '', $phone);

        return $digits !== '' ? 'tel:'.$digits : null;
    }

    private function site(Content $content): ?Site
    {
        return Site::withoutGlobalScope(SiteScope::class)->find($content->site_id);
    }
}
