<?php

namespace App\Publishing\Blocks;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteNarrative;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\SiteContact;

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
    public function __construct(
        private readonly BlockPageComposer $composer,
        private readonly ServiceAreaResolver $serviceAreas,
        private readonly ServiceCardBlurb $cardBlurb,
        private readonly SiteContact $contact,
    ) {}

    /**
     * @param  array<string, mixed>  $slots  the resolved slot_payload (from MetaBlobAssembler)
     * @param  array<string, array<string, mixed>>  $images  the resolved image map
     * @param  bool  $preview  operator proof-view context — build all sections with labeled placeholders
     *                         for missing data (see {@see BlockPageComposer::composeHome}); publish omits.
     * @param  bool  $mapAvailable  whether the tenant has service-area map geometry (resolved once by the
     *                              caller and carried on the blob) — drives the interactive areas map.
     */
    public function compose(Content $content, array $slots, array $images, bool $preview = false, bool $mapAvailable = false): ?string
    {
        if ($content->kind !== ContentKind::Page) {
            return null;
        }

        $ctx = $this->context($this->site($content));

        // Dispatch by the page's identity: Home by page_type, standard pages (Utility page_type) by
        // their finer standard_type. A type whose block composer hasn't shipped returns null — the blob
        // then carries no post_content and the plugin falls back to the existing render.
        if ($content->page_type === PageType::Home) {
            return $this->composeHome($content, $slots, $images, $ctx, $preview, $mapAvailable);
        }

        if ($content->standard_type === StandardPageType::WhyChooseUs) {
            return $this->composeWhyChooseUs($content, $slots, $images, $ctx, $preview);
        }

        if ($content->standard_type === StandardPageType::About) {
            return $this->composeAbout($content, $slots, $images, $ctx, $preview);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeHome(Content $content, array $slots, array $images, PageContext $ctx, bool $preview, bool $mapAvailable): string
    {
        $site = $this->site($content);
        $siteId = (string) $content->site_id;

        return $this->composer->composeHome(
            slots: $slots,
            images: $images,
            serviceCards: $this->serviceCards($content, $site),
            ctx: $ctx,
            trustStats: $this->trustStats($content),
            credibilityBadges: $this->credibilityBadges($content),
            differentiators: $this->differentiators($content),
            testimonials: $this->testimonials($content),
            serviceAreaCounties: $this->serviceAreas->resolve($siteId)['counties'],
            serviceAreasByCounty: $this->serviceAreas->byCounty($siteId),
            processSteps: $this->processSteps($content),
            certifications: $this->certifications($content),
            guarantee: $this->guarantee($content),
            preview: $preview,
            serviceAreaMapAvailable: $mapAvailable,
        );
    }

    /**
     * The dedicated Why Choose Us page — its body is the §1 differentiators, reinforced by the
     * guarantee, real credentials, and substantiated client voice (all reusing the home resolvers).
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeWhyChooseUs(Content $content, array $slots, array $images, PageContext $ctx, bool $preview): string
    {
        return $this->composer->composeWhyChooseUs(
            slots: $slots,
            images: $images,
            ctx: $ctx,
            differentiators: $this->differentiators($content),
            credibilityBadges: $this->credibilityBadges($content),
            guarantee: $this->guarantee($content),
            certifications: $this->certifications($content),
            testimonials: $this->testimonials($content),
            trustStats: $this->trustStats($content),
            preview: $preview,
        );
    }

    /**
     * The About page — a brand narrative. Story + mission render the DRAFTED, voice-expanded prose
     * (the About drafter's whole job), falling back to the raw §1 narrative when a slot is empty;
     * values + team render from §1 intake (reliable structure). About is brand-critical and
     * review-gated, so the voice-expanded prose is operator-checked before it can publish.
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeAbout(Content $content, array $slots, array $images, PageContext $ctx, bool $preview): string
    {
        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->first();

        return $this->composer->composeAbout(
            slots: $slots,
            images: $images,
            ctx: $ctx,
            story: $this->storyParagraphs($this->slotString($slots, 'our_story') ?: (string) ($narrative->story ?? '')),
            mission: trim($this->slotString($slots, 'mission') ?: (string) ($narrative->mission ?? '')),
            values: $this->titleDescList(is_array($narrative?->values) ? $narrative->values : []),
            team: $this->teamMembers($narrative),
            credibilityBadges: $this->credibilityBadges($content),
            trustStats: $this->trustStats($content),
            preview: $preview,
        );
    }

    /**
     * Split brand-story prose (possibly the drafter's HTML) into clean plain-text paragraphs — the
     * block composer emits each as its own <p>. Explicit paragraph breaks (</p> or blank lines) win;
     * remaining tags are stripped so nothing invalid reaches the block markup.
     *
     * @return list<string>
     */
    private function storyParagraphs(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $chunks = preg_split('#</p>|\n\s*\n#i', $raw) ?: [$raw];

        $out = [];
        foreach ($chunks as $chunk) {
            $text = trim(html_entity_decode(strip_tags((string) $chunk), ENT_QUOTES, 'UTF-8'));
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Normalize a {title, description} list (values / differentiators) to the section shape, capped.
     *
     * @param  array<int, mixed>  $items
     * @return list<array{title: string, description: string}>
     */
    private function titleDescList(array $items, int $cap = 6): array
    {
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

        return array_slice($out, 0, $cap);
    }

    /**
     * The tenant's real team from §1 — {name, role, bio, photo_url}, verbatim. Only members with a name
     * appear; never fabricated. (No drafted team slot exists — the team is structured intake.)
     *
     * @return list<array{name: string, role: string, bio: string, photo_url: string}>
     */
    private function teamMembers(?SiteNarrative $narrative): array
    {
        $items = is_array($narrative?->team) ? $narrative->team : [];

        $out = [];
        foreach ($items as $member) {
            if (! is_array($member)) {
                continue;
            }
            $name = trim((string) ($member['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'role' => trim((string) ($member['role'] ?? $member['title'] ?? '')),
                'bio' => trim((string) ($member['bio'] ?? $member['description'] ?? '')),
                'photo_url' => trim((string) ($member['photo_url'] ?? $member['photo'] ?? '')),
            ];
        }

        return array_slice($out, 0, 8);
    }

    /** First scalar of a slot value (single slots may arrive as a string or a one-element array). */
    private function slotString(array $slots, string $key): string
    {
        $value = $slots[$key] ?? '';
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return trim((string) $value);
    }

    /**
     * The shared page context — phone resolves via SiteContact (primary Location's number, else the
     * site business phone), so a guided-onboarded tenant that set only the wizard phone still shows a
     * number. Emergency uses the dedicated after-hours line when set, else the main number.
     */
    private function context(?Site $site): PageContext
    {
        $phone = $site !== null ? $this->contact->phone($site) : null;
        $emergencyPhone = $site !== null ? $this->contact->emergencyPhone($site) : null;

        return new PageContext(
            phoneDisplay: $phone,
            phoneTel: $this->contact->tel($phone),
            emergency: $site !== null && (bool) $site->offers_emergency,
            emergencyDisplay: $emergencyPhone,
            emergencyTel: $this->contact->tel($emergencyPhone),
        );
    }

    /**
     * The tenant's real "how it works" — ProofItem type=process (title + description). Descriptive, not
     * a claim, so substantiation isn't required. Fewer than 2 real steps → [] so the section keeps its
     * safe business-agnostic default rather than a lopsided one.
     *
     * @return list<array{title: string, description: string}>
     */
    private function processSteps(Content $content): array
    {
        $items = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('type', ProofType::Process->value)
            ->orderBy('created_at')
            ->limit(3)
            ->get();

        $steps = [];
        foreach ($items as $item) {
            $title = $this->payloadString($item, ['title', 'label']);
            $description = $this->payloadString($item, ['description', 'text']);
            if ($title !== '' && $description !== '') {
                $steps[] = ['title' => $title, 'description' => $description];
            }
        }

        return count($steps) >= 2 ? $steps : [];
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
     * The tenant's guarantee/warranty from the narrative — {name, description}, verbatim. Empty when
     * none is captured (the band then omits). Never fabricated.
     *
     * @return array{name: string, description: string}
     */
    private function guarantee(Content $content): array
    {
        $g = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->value('guarantee');
        $g = is_array($g) ? $g : [];

        return ['name' => trim((string) ($g['name'] ?? '')), 'description' => trim((string) ($g['description'] ?? ''))];
    }

    /**
     * The tenant's certifications/credentials from the narrative — a list of {label, number?, logo_url?},
     * verbatim, per-item. Only captured credentials appear (never fabricated); capped so the row stays a
     * row.
     *
     * @return list<array{label: string, number: string, logo_url: string}>
     */
    private function certifications(Content $content): array
    {
        $items = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->value('certifications');
        $items = is_array($items) ? $items : [];

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $logo = trim((string) ($item['logo_url'] ?? ''));
            if ($label === '' && $logo === '') {
                continue;
            }
            $out[] = ['label' => $label, 'number' => trim((string) ($item['number'] ?? '')), 'logo_url' => $logo];
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
            $cards[] = [
                'title' => $title,
                // Never null — child-page description if it exists, else a generated keyword-grounded
                // blurb (see {@see ServiceCardBlurb}), so no card ships as a bare "Learn more".
                'blurb' => $this->cardBlurb->for($page),
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

    private function site(Content $content): ?Site
    {
        return Site::withoutGlobalScope(SiteScope::class)->find($content->site_id);
    }
}
