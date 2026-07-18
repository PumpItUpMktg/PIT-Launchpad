<?php

namespace App\Publishing\Blocks;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Enums\StandardPageType;
use App\Enums\VoiceStatus;
use App\Local\Proof\LocalJobProvider;
use App\Local\Proof\LocalReviewProvider;
use App\Local\Proof\NullLocalReviews;
use App\Local\Proof\ServiceJobProvider;
use App\Local\Proof\ServiceReviewProvider;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\Location;
use App\Models\PageConfig;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\SiteNarrative;
use App\Models\VoiceProfile;
use App\Publishing\Legal\LegalContext;
use App\Publishing\Legal\LegalTemplates;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\SiteContact;
use App\Support\BusinessHours;

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
        private readonly LocalReviewProvider $localReviews,
        private readonly LocalJobProvider $localJobs,
        private readonly ServiceReviewProvider $serviceReviews,
        private readonly ServiceJobProvider $serviceJobs,
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

        if ($content->page_type === PageType::Service) {
            return $this->composeSpoke($content, $slots, $images, $ctx, $preview);
        }

        if ($content->page_type === PageType::Hub) {
            return $this->composeHub($content, $slots, $images, $ctx, $preview);
        }

        // A location page pinned to its GBP Location record composes the block pattern; market-era
        // location pages WITHOUT a pin (no location_id) keep the null fallback → the Elementor path.
        if ($content->page_type === PageType::Location && $content->location_id !== null) {
            return $this->composeLocation($content, $slots, $images, $preview);
        }

        if ($content->standard_type === StandardPageType::WhyChooseUs) {
            return $this->composeWhyChooseUs($content, $slots, $images, $ctx, $preview);
        }

        if ($content->standard_type === StandardPageType::About) {
            return $this->composeAbout($content, $slots, $images, $ctx, $preview);
        }

        if ($content->standard_type === StandardPageType::Faq) {
            return $this->composer->composeFaq($slots, $ctx, $this->faqItems($slots), $preview);
        }

        if ($content->standard_type === StandardPageType::AreasWeServe) {
            $siteId = (string) $content->site_id;

            return $this->composer->composeAreas(
                slots: $slots,
                ctx: $ctx,
                counties: $this->serviceAreas->resolve($siteId)['counties'],
                byCounty: $this->serviceAreas->byCounty($siteId),
                preview: $preview,
                mapAvailable: $mapAvailable,
            );
        }

        if ($content->standard_type === StandardPageType::Contact) {
            return $this->composeContact($content, $slots, $images, $ctx, $preview, $mapAvailable);
        }

        if ($content->standard_type === StandardPageType::Privacy || $content->standard_type === StandardPageType::Terms) {
            return $this->composeLegal($content, $slots);
        }

        return null;
    }

    /**
     * The Contact page — hero + the real NAP (phone via the shared context, email/address/hours from the
     * primary §1 Location) + a request-service CTA. Every channel is honest and per-field data-gated.
     *
     * The ADDRESS renders only for a real STOREFRONT (`Location.is_storefront`) — many home-services
     * businesses are mobile-only with no location customers visit; showing their base address would
     * invite walk-ins to a garage. Emergency tenants get an honest "Emergencies — 24/7" hours row.
     * NAP consistency: the same primary-Location record feeds this page, the footer, and the schema.
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeContact(Content $content, array $slots, array $images, PageContext $ctx, bool $preview, bool $mapAvailable): string
    {
        $location = $this->primaryLocation($content);
        $storefront = (bool) ($location?->is_storefront);

        $hours = $this->businessHours($location);
        if ($ctx->emergency && $ctx->hasPhone()) {
            $hours[] = ['label' => 'Emergencies', 'value' => '24/7 — call any time'];
        }

        // A configured lead-form embed (GHL iframe on PageConfig) makes the form section REAL — the
        // markup carries the [lp_form] shortcode and the plugin renders the embed server-side (the
        // iframe itself rides the blob's form_embed, never kses'd post_content).
        $hasForm = trim((string) (PageConfig::query()->where('content_id', $content->id)->value('form_embed') ?? '')) !== '';

        return $this->composer->composeContact(
            slots: $slots,
            ctx: $ctx,
            email: is_string($location?->email) && trim($location->email) !== '' ? trim($location->email) : null,
            address: $storefront && is_string($location->address) && trim($location->address) !== '' ? trim($location->address) : null,
            hours: $hours,
            serviceAreaBrief: $this->slotString($slots, 'service_area_brief'),
            audience: $this->audience($content),
            hasForm: $hasForm,
            images: $images,
            mapAvailable: $mapAvailable,
            storefront: $storefront,
            preview: $preview,
        );
    }

    /**
     * The primary location's hours as CUSTOMER-FACING display rows — 12-hour am/pm times, full day
     * names, consecutive identical days collapsed ("Monday – Saturday / 8am – 6pm"); closed days drop.
     * Display only — when LocalBusiness openingHoursSpecification lands it stays per-day, so the
     * collapse costs no SEO. Empty when nothing is captured.
     *
     * @return list<array{label: string, value: string}>
     */
    private function businessHours(?Location $location): array
    {
        return BusinessHours::displayRows(is_array($location?->hours) ? $location->hours : null);
    }

    /**
     * A legal page — template-driven, never AI-generated (fabricated legal terms are false
     * representations). The {@see LegalTemplates} boilerplate is filled with the tenant's REAL data
     * (business name, site host, captured contact channels) and stamped with a stable effective date
     * (the page's creation date), so the same page always renders the same document.
     *
     * @param  array<string, mixed>  $slots
     */
    private function composeLegal(Content $content, array $slots): string
    {
        $site = $this->site($content);
        $brand = trim((string) ($site->brand_name ?? ''));

        $ctx = new LegalContext(
            business: $brand !== '' ? $brand : 'this business',
            siteUrl: $site?->domain_url,
            email: $this->legalContactEmail($content),
            phone: $site !== null ? $this->contact->phone($site) : null,
            effectiveDate: $content->created_at?->format('F j, Y') ?? '',
        );

        $templates = new LegalTemplates;
        $doc = $content->standard_type === StandardPageType::Terms
            ? $templates->terms($ctx)
            : $templates->privacy($ctx);

        return $this->composer->composeLegal($slots, $doc['title'], $doc['effective_date'], $doc['sections']);
    }

    /** The tenant's primary contact email (the earliest Location's email), or null — never invented. */
    private function legalContactEmail(Content $content): ?string
    {
        $email = $this->primaryLocation($content)?->email;

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }

    /**
     * The drafted FAQ question/answer pairs from the resolved `faq` slot (drafter-generated, shaped to
     * {question, answer} by the SlotShaper). Only complete pairs render; capped. Same key the plugin's
     * FAQPage schema reads, so accordion + schema stay in lockstep.
     *
     * @param  array<string, mixed>  $slots
     * @return list<array{question: string, answer: string}>
     */
    private function faqItems(array $slots): array
    {
        $raw = $slots['faq'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? $item['q'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? $item['a'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $out[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return array_slice($out, 0, 12);
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
            differentiators: $this->differentiators($content),
            testimonials: $this->testimonials($content),
            serviceAreaCounties: $this->serviceAreas->resolve($siteId)['counties'],
            serviceAreasByCounty: $this->serviceAreas->byCounty($siteId),
            processSteps: $this->processSteps($content),
            certifications: $this->mergedCredentials($content),
            guarantee: $this->guarantee($content),
            preview: $preview,
            serviceAreaMapAvailable: $mapAvailable,
        );
    }

    /**
     * A SPOKE service page (hub+spoke relay) — resolves the pinned §1 Service's enrichment
     * (symptoms / scope / process / cost factors + honest range / owner-triggered comparison), the
     * page's primary keyword (the H1), the silo's related-links spine (hub + siblings, never
     * cross-silo), and the provider-fed service-scoped jobs/reviews, then hands it all to
     * {@see BlockPageComposer::composeSpoke}. Old-kit slot keys (hero_problem / problem_explainer /
     * solution_overview / service_features) are honored as fallbacks so an un-regenerated v1 page
     * still renders its copy.
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeSpoke(Content $content, array $slots, array $images, PageContext $ctx, bool $preview): string
    {
        $service = $this->pinnedService($content);

        // Old-kit fallbacks: v1 pages drafted hero_problem/hero_solution; the intro falls back to
        // the v1 explainer pair so an un-regenerated page keeps its body.
        $slots['hero_headline'] = $slots['hero_headline'] ?? $slots['hero_problem'] ?? null;
        $slots['hero_subhead'] = $slots['hero_subhead'] ?? $slots['hero_solution'] ?? null;
        $intro = $this->storyParagraphs($this->slotString($slots, 'svc_intro'));
        if ($intro === []) {
            $intro = array_merge(
                $this->storyParagraphs($this->slotString($slots, 'problem_explainer')),
                $this->storyParagraphs($this->slotString($slots, 'solution_overview')),
            );
        }

        // Symptoms: the record field, else the service's captured problem phrases (§1) — literal
        // customer symptoms, honest fallback. Scope: the record field, else the v1 features slot.
        $symptoms = $this->strings($service?->symptoms);
        if ($symptoms === [] && $service !== null) {
            $symptoms = $service->problems()->pluck('phrase')
                ->map(fn ($p): string => trim((string) $p))
                ->filter(fn (string $p): bool => $p !== '')
                ->values()->all();
        }
        $scope = $this->strings($service?->scope_items);
        if ($scope === []) {
            $scope = $this->stringList($slots, 'service_features');
        }

        return $this->composer->composeSpoke(
            slots: $slots,
            images: $images,
            ctx: $ctx,
            keyword: $this->pageKeyword($content),
            intro: $intro,
            symptoms: array_slice($symptoms, 0, 8),
            symptomsIntro: $this->slotString($slots, 'symptoms_intro'),
            scopeItems: array_slice($scope, 0, 10),
            scopeIntro: $this->slotString($slots, 'scope_intro'),
            processSteps: $this->serviceProcessSteps($content, $service),
            costCopy: $this->storyParagraphs($this->slotString($slots, 'cost_copy')),
            costFactors: array_slice($this->strings($service?->cost_factors), 0, 8),
            costRange: $this->costRangeLine($service),
            comparison: is_array($service?->comparison) ? $service->comparison : [],
            jobs: $service !== null ? $this->serviceJobCards($service) : [],
            reviews: $service !== null ? $this->serviceReviewQuotes($service) : [],
            related: $this->relatedServiceLinks($content),
            trustStats: $this->trustStats($content),
            faqs: $this->faqItems($slots),
            preview: $preview,
            hasForm: $this->hasLeadForm($content),
        );
    }

    /**
     * A configured lead-capture form makes the service-description row a 60/40 two-column (copy +
     * [lp_form]). The embed is resolved per-page (PageConfig.form_embed) OR site-wide
     * (ConversionConfig.ghl_form_embed) — the same fallback the meta-blob's form_embed uses, so the
     * two-column and the rendered embed always agree.
     */
    private function hasLeadForm(Content $content): bool
    {
        $page = trim((string) (PageConfig::query()->where('content_id', $content->id)->value('form_embed') ?? ''));
        if ($page !== '') {
            return true;
        }

        $site = ConversionConfig::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->value('ghl_form_embed');

        return is_string($site) && trim($site) !== '';
    }

    /**
     * A HUB (silo pillar) page — the category keyword H1, the drafted intro/why, and the silo's
     * SERVICES GRID: one card per child spoke, resolved fresh at compose time (so a repush after
     * adding a spoke refreshes the grid), each linking the spoke's real permalink. Reviews aggregate
     * across the hub's spokes via the service-scoped provider (strictly gated).
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeHub(Content $content, array $slots, array $images, PageContext $ctx, bool $preview): string
    {
        $slots['hero_headline'] = $slots['hero_headline'] ?? $slots['hero_problem'] ?? null;
        $slots['hero_subhead'] = $slots['hero_subhead'] ?? $slots['hero_solution'] ?? null;

        $intro = $this->storyParagraphs($this->slotString($slots, 'hub_intro'));
        if ($intro === []) {
            $intro = $this->storyParagraphs($this->slotString($slots, 'problem_explainer'));
        }

        return $this->composer->composeHub(
            slots: $slots,
            images: $images,
            ctx: $ctx,
            keyword: $this->pageKeyword($content),
            intro: $intro,
            spokeCards: $this->spokeCards($content),
            why: $this->storyParagraphs($this->slotString($slots, 'hub_why')),
            processSteps: $this->processSteps($content),
            certifications: $this->mergedCredentials($content),
            reviews: $this->hubReviewQuotes($content),
            trustStats: $this->trustStats($content),
            faqs: $this->faqItems($slots),
            preview: $preview,
        );
    }

    /** The page's primary keyword (Content.target_keyword_id — the Option A carry-over), or ''. */
    private function pageKeyword(Content $content): string
    {
        return trim((string) $content->targetKeyword()->withoutGlobalScope(SiteScope::class)->value('query'));
    }

    /** The spoke page's §1 Service subject: its pin, else the silo's first service. */
    private function pinnedService(Content $content): ?Service
    {
        if ($content->primary_service_id !== null) {
            $pinned = Service::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $content->site_id)
                ->find($content->primary_service_id);
            if ($pinned !== null) {
                return $pinned;
            }
        }

        if ($content->silo_id === null) {
            return null;
        }

        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->whereHas('silos', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->whereKey($content->silo_id))
            ->orderBy('name')
            ->first();
    }

    /**
     * The spoke's process: the RECORD's ordered steps when captured (plain strings → step cards),
     * else the tenant's captured process, else [] (the section keeps its safe default).
     *
     * @return list<array{title: string, description: string}>
     */
    private function serviceProcessSteps(Content $content, ?Service $service): array
    {
        $steps = $this->strings($service?->process_steps);
        if ($steps !== []) {
            return array_map(fn (string $step): array => ['title' => $step, 'description' => ''], array_slice($steps, 0, 6));
        }

        return $this->processSteps($content);
    }

    /**
     * The honest price line — ONLY when the record carries a real range: "Typical range: $X–$Y per
     * unit". Absent/partial range ⇒ '' (the cost section renders factors-only, never a blank price).
     */
    private function costRangeLine(?Service $service): string
    {
        $range = is_array($service?->price_range) ? $service->price_range : [];
        $low = $range['low'] ?? null;
        $high = $range['high'] ?? null;
        if (! is_numeric($low) || ! is_numeric($high) || (float) $high <= 0) {
            return '';
        }

        $fmt = fn (float $n): string => '$'.number_format($n, $n === floor($n) ? 0 : 2);
        $unit = trim((string) ($range['unit'] ?? ''));

        return 'Typical range: '.$fmt((float) $low).'–'.$fmt((float) $high).($unit !== '' ? ' '.$unit : '');
    }

    /**
     * The silo's internal-link spine for a spoke: the HUB page first (always, when materialized),
     * then up to three sibling spokes. Real permalinks only; never cross-silo.
     *
     * @return list<array{label: string, url: string}>
     */
    private function relatedServiceLinks(Content $content): array
    {
        if ($content->silo_id === null) {
            return [];
        }

        $site = $this->site($content);
        $home = is_string($site?->domain_url) && trim((string) $site->domain_url) !== ''
            ? rtrim((string) $site->domain_url, '/').'/'
            : '/';

        $links = [];

        $hub = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('silo_id', $content->silo_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Hub->value)
            ->whereNotNull('slug')
            ->first();
        if ($hub !== null && trim((string) $hub->title) !== '') {
            $links[] = ['label' => 'All '.lcfirst(trim((string) $hub->title)), 'url' => $home.ltrim((string) $hub->slug, '/')];
        }

        $siblings = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('silo_id', $content->silo_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Service->value)
            ->whereKeyNot($content->id)
            ->whereNotNull('slug')
            ->orderBy('title')
            ->limit(3)
            ->get(['title', 'slug']);
        foreach ($siblings as $sibling) {
            $title = trim((string) $sibling->title);
            if ($title !== '') {
                $links[] = ['label' => $title, 'url' => $home.ltrim((string) $sibling->slug, '/')];
            }
        }

        return $links;
    }

    /**
     * The hub's services grid: one card per child spoke page in the silo — title, the service's
     * short_description (else the shared keyword-grounded blurb), and the REAL permalink. Resolved
     * fresh on every compose, so regenerate/repush after adding a spoke refreshes the grid.
     *
     * @return list<array{title: string, blurb: string, url: string}>
     */
    private function spokeCards(Content $content): array
    {
        if ($content->silo_id === null) {
            return [];
        }

        $site = $this->site($content);
        $home = is_string($site?->domain_url) && trim((string) $site->domain_url) !== ''
            ? rtrim((string) $site->domain_url, '/').'/'
            : '/';

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('silo_id', $content->silo_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Service->value)
            ->whereNotNull('slug')
            ->orderBy('title')
            ->limit(12)
            ->get();

        $cards = [];
        foreach ($pages as $page) {
            $title = trim((string) $page->title);
            if ($title === '') {
                continue;
            }
            $service = $page->primary_service_id !== null
                ? Service::withoutGlobalScope(SiteScope::class)->where('site_id', $content->site_id)->find($page->primary_service_id)
                : null;
            $short = trim((string) ($service->short_description ?? ''));
            $cards[] = [
                'title' => $title,
                'blurb' => $short !== '' ? $short : $this->cardBlurb->for($page),
                'url' => $home.ltrim((string) $page->slug, '/'),
            ];
        }

        return $cards;
    }

    /**
     * Provider-fed service-scoped reviews mapped to the testimonials shape. Contract-first: the
     * Null binding returns [] and the section omits entirely — never a placeholder or fabrication.
     *
     * @return list<array{quote: string, author: string, role: string, stars: int}>
     */
    private function serviceReviewQuotes(Service $service): array
    {
        $out = [];
        foreach ($this->serviceReviews->for($service) as $review) {
            $out[] = [
                'quote' => $review->text,
                'author' => $review->authorFirst,
                'role' => $review->town,
                'stars' => max(0, min(5, $review->rating)),
            ];
        }

        return array_slice($out, 0, 3);
    }

    /**
     * Provider-fed service-scoped jobs mapped to the job-card shape. Same contract-first gating.
     *
     * @return list<array{title: string, description: string, photo: string, town: string, date: string}>
     */
    private function serviceJobCards(Service $service): array
    {
        $out = [];
        foreach ($this->serviceJobs->for($service) as $job) {
            $out[] = [
                'title' => $job->title,
                'description' => $job->description,
                'photo' => (string) ($job->photos[0] ?? ''),
                'town' => $job->town,
                'date' => (string) ($job->date ?? ''),
            ];
        }

        return array_slice($out, 0, 3);
    }

    /**
     * The hub's review pool: the union of its spokes' service-scoped reviews (capped). Strictly
     * provider-gated like every review surface.
     *
     * @return list<array{quote: string, author: string, role: string, stars: int}>
     */
    private function hubReviewQuotes(Content $content): array
    {
        if ($content->silo_id === null) {
            return [];
        }

        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->whereHas('silos', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->whereKey($content->silo_id))
            ->orderBy('name')
            ->get();

        $out = [];
        foreach ($services as $service) {
            foreach ($this->serviceReviewQuotes($service) as $quote) {
                $out[] = $quote;
                if (count($out) >= 3) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * A nullable json list attribute as clean strings — trimmed, empties dropped, order kept.
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * A LOCATION page — resolves the pinned §1 Location (its city/state, served towns, own phone),
     * the site's trade (the deterministic H1 formula), the catalog services with the LIVE-page link
     * rule, and the provider-fed local reviews/jobs, then hands it all to
     * {@see BlockPageComposer::composeLocation}. A stale pin (the Location was deleted) returns null
     * so the blob carries no post_content and the plugin falls back — never a half-composed page.
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeLocation(Content $content, array $slots, array $images, bool $preview): ?string
    {
        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->find($content->location_id);
        if ($location === null) {
            return null;
        }

        ['city' => $city, 'state' => $state] = $location->cityState();
        if ($city === '') {
            $city = trim((string) $location->name);
        }

        $towns = $this->servedTownNames($location);

        // Coverage prose: the drafted slot when generated, else the honest derived sentence naming
        // ONLY the captured towns (real data, never invented — and never a bare keyword dump).
        $coverage = $this->storyParagraphs($this->slotString($slots, 'loc_coverage'));
        if ($coverage === [] && $towns !== []) {
            $base = $city !== '' ? ' from our '.$city.' location' : '';
            $coverage = ['We serve '.$this->naturalList($towns).' and the surrounding area'.$base.'.'];
        }

        return $this->composer->composeLocation(
            slots: $slots,
            images: $images,
            ctx: $this->locationContext($content, $location),
            city: $city,
            state: $state,
            trade: $this->trade($content),
            intro: $this->storyParagraphs($this->slotString($slots, 'loc_intro')),
            servicesIntro: $this->slotString($slots, 'loc_services_intro'),
            serviceCards: $this->locationServiceCards($content),
            coverage: $coverage,
            reviews: $this->locationReviews($location),
            jobs: $this->locationJobs($location),
            faqs: $this->faqItems($slots),
            trustStats: $this->trustStats($content),
            preview: $preview,
        );
    }

    /**
     * The location page's context: this LOCATION's own phone leads (each GBP location may carry its
     * own tracked line — the CTA and hero call button must show the number a local caller should
     * dial), falling back to the site-wide resolution when the location has none.
     */
    private function locationContext(Content $content, Location $location): PageContext
    {
        $site = $this->site($content);
        $phone = trim((string) $location->phone);
        if ($phone === '') {
            return $this->context($site);
        }

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
     * The location's served-town names (state suffix dropped — coverage prose reads "Norristown,
     * Audubon, and Eagleville", not a comma soup of state codes). Ungeocodable towns still count:
     * the claim is the operator's captured list, not the geocoder's success.
     *
     * @return list<string>
     */
    private function servedTownNames(Location $location): array
    {
        $names = [];
        foreach ($location->served_towns ?? [] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_slice($names, 0, 12);
    }

    /**
     * "A", "A and B", "A, B, and C" — the readable list the coverage fallback sentence uses.
     *
     * @param  non-empty-list<string>  $names
     */
    private function naturalList(array $names): string
    {
        $count = count($names);
        if ($count === 1) {
            return $names[0];
        }
        if ($count === 2) {
            return $names[0].' and '.$names[1];
        }

        return implode(', ', array_slice($names, 0, -1)).', and '.$names[$count - 1];
    }

    /** The site's captured trade (the owner interview / guided intake seed), or ''. */
    private function trade(Content $content): string
    {
        $trade = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->value('trade');

        return is_string($trade) ? trim($trade) : '';
    }

    /**
     * The service catalog as location-page cards — THE LINK RULE: a service links only when a LIVE
     * service page exists for it (materialized + actually pushed to WordPress, `wp_post_id` set);
     * otherwise it renders as text. A location page must never link a visitor to a 404.
     *
     * @return list<array{title: string, blurb: string, url: string}>
     */
    private function locationServiceCards(Content $content): array
    {
        $site = $this->site($content);
        $home = is_string($site?->domain_url) && trim((string) $site->domain_url) !== ''
            ? rtrim((string) $site->domain_url, '/').'/'
            : '/';

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Service->value)
            ->whereNotNull('slug')
            ->whereNotNull('wp_post_id')
            ->get(['title', 'slug', 'primary_service_id']);

        $byService = [];
        $byTitle = [];
        foreach ($pages as $page) {
            $url = $home.ltrim((string) $page->slug, '/');
            if ($page->primary_service_id !== null) {
                $byService[(string) $page->primary_service_id] = $url;
            }
            $byTitle[mb_strtolower(trim((string) $page->title))] = $url;
        }

        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->orderBy('created_at')
            ->limit(6)
            ->get();

        $cards = [];
        foreach ($services as $service) {
            $name = trim((string) $service->name);
            if ($name === '') {
                continue;
            }
            $cards[] = [
                'title' => $name,
                'blurb' => trim((string) $service->description),
                'url' => $byService[(string) $service->id] ?? $byTitle[mb_strtolower($name)] ?? '',
            ];
        }

        return $cards;
    }

    /**
     * Provider-fed local reviews mapped to the testimonials shape (author first name + town as the
     * byline). Contract-first: the default {@see NullLocalReviews} binding returns
     * [] and the section omits entirely — never a placeholder, never a fabricated quote.
     *
     * @return list<array{quote: string, author: string, role: string, stars: int}>
     */
    private function locationReviews(Location $location): array
    {
        $out = [];
        foreach ($this->localReviews->for($location) as $review) {
            $out[] = [
                'quote' => $review->text,
                'author' => $review->authorFirst,
                'role' => $review->town,
                'stars' => max(0, min(5, $review->rating)),
            ];
        }

        return array_slice($out, 0, 3);
    }

    /**
     * Provider-fed nearby jobs mapped to the job-card shape. Same contract-first gating as reviews.
     *
     * @return list<array{title: string, description: string, photo: string, town: string, date: string}>
     */
    private function locationJobs(Location $location): array
    {
        $out = [];
        foreach ($this->localJobs->for($location) as $job) {
            $out[] = [
                'title' => $job->title,
                'description' => $job->description,
                'photo' => (string) ($job->photos[0] ?? ''),
                'town' => $job->town,
                'date' => (string) ($job->date ?? ''),
            ];
        }

        return array_slice($out, 0, 3);
    }

    /**
     * A list slot's values as clean plain strings — the SlotShaper flattens a `list` slot to a plain
     * array of strings, but read defensively (an item may still arrive as a {text/label} map). Trimmed,
     * empties dropped, order preserved.
     *
     * @param  array<string, mixed>  $slots
     * @return list<string>
     */
    private function stringList(array $slots, string $key): array
    {
        $raw = $slots[$key] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $value = is_array($item)
                ? trim((string) ($item['text'] ?? $item['label'] ?? $item['value'] ?? ''))
                : trim((string) $item);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** The tenant's primary (earliest) §1 Location, tenant-scoped, or null. */
    private function primaryLocation(Content $content): ?Location
    {
        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * The dedicated Why Choose Us page — its body IS the differentiators grid, reinforced by the
     * audience-ordered credibility strip, real credentials, the guarantee, and substantiated client
     * voice (all reusing the home resolvers). When the narrative captured no differentiators, honest
     * ones are DERIVED from real §1 data ({@see derivedDifferentiators}) — a Why-Choose-Us page must
     * have reasons; it never ships as hero + guarantee + CTAs with the "why" absent.
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeWhyChooseUs(Content $content, array $slots, array $images, PageContext $ctx, bool $preview): string
    {
        $audience = $this->audience($content);

        $differentiators = $this->differentiators($content);
        if ($differentiators === []) {
            $differentiators = $this->derivedDifferentiators($content, $audience);
        }

        return $this->composer->composeWhyChooseUs(
            slots: $slots,
            images: $images,
            ctx: $ctx,
            differentiators: $differentiators,
            guarantee: $this->guarantee($content),
            certifications: $this->mergedCredentials($content),
            testimonials: $this->testimonials($content),
            trustStats: $this->trustStats($content),
            credibilityBadges: $this->credibilityBadges($content, audience: $audience),
            preview: $preview,
        );
    }

    /**
     * DERIVED differentiators — the fallback when the narrative captured none. Every card traces to a
     * real §1 fact (the guarantee's own name, the emergency flag, a substantiated license, the actual
     * service catalog, captured process steps); nothing is a bare marketing claim, so no card can
     * fabricate a capability or credential. Ordered by AUDIENCE (commercial leads with process/scope —
     * predictability and one-vendor coverage; homeowner leads with the guarantee and emergency help).
     * Fewer than 2 derivable cards → [] (a one-card grid reads broken; the section then data-gates,
     * degrade by omission).
     *
     * @return list<array{title: string, description: string}>
     */
    private function derivedDifferentiators(Content $content, string $audience): array
    {
        $cards = [];

        // The tenant's real guarantee, by its own name.
        $guarantee = $this->guarantee($content);
        if ($guarantee['name'] !== '') {
            $cards['guarantee'] = [
                'title' => $guarantee['name'],
                'description' => $guarantee['description'] !== '' ? $guarantee['description'] : 'We stand behind the work — in writing.',
            ];
        }

        // Emergency coverage — only when the site actually offers it (§1 flag).
        $site = $this->site($content);
        if ((bool) $site?->offers_emergency) {
            $cards['emergency'] = [
                'title' => 'Emergency response',
                'description' => 'Emergency service is part of what we offer — when something fails, call.',
            ];
        }

        // Licensed — only when substantiated license proof exists; the description IS the real credential.
        $license = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('is_substantiated', true)
            ->where('type', ProofType::License->value)
            ->orderBy('created_at')
            ->first();
        $licenseLabel = $license !== null ? $this->payloadString($license, ['label', 'text']) : '';
        if ($licenseLabel !== '') {
            $cards['licensed'] = ['title' => 'Licensed professionals', 'description' => $licenseLabel];
        }

        // One team for the whole job — enumerates the REAL service catalog (verifiable on the site).
        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->orderBy('created_at')
            ->limit(3)
            ->pluck('name')
            ->map(fn ($n): string => trim((string) $n))
            ->filter(fn (string $n): bool => $n !== '')
            ->values();
        if ($services->count() >= 2) {
            $cards['services'] = [
                'title' => 'One team for the whole job',
                'description' => 'From '.$services->slice(0, -1)->implode(', ').' to '.$services->last().' — no hand-offs.',
            ];
        }

        // A clear process — the tenant's own captured steps (descriptive §1 intake).
        $steps = $this->processSteps($content);
        if ($steps !== []) {
            $cards['process'] = [
                'title' => 'A clear, predictable process',
                'description' => collect($steps)->pluck('title')->implode(' → ').'.',
            ];
        }

        // Audience sets which reasons LEAD; data already decided which exist.
        $order = $audience === 'commercial'
            ? ['process', 'services', 'licensed', 'guarantee', 'emergency']
            : ['guarantee', 'emergency', 'licensed', 'services', 'process'];

        $ordered = [];
        foreach ($order as $key) {
            if (isset($cards[$key])) {
                $ordered[] = $cards[$key];
            }
        }

        return count($ordered) >= 2 ? array_slice($ordered, 0, 6) : [];
    }

    /**
     * The About page — the trust-conversion brand narrative. Story renders the DRAFTED, voice-expanded
     * prose, falling back to the client's OWN §1 story (their words, prose by nature). The MISSION
     * renders ONLY the drafted slot — the raw intake field is an operator brief (often keywords), and
     * rendering it verbatim was the canonical raw-intake leak; an ungenerated page omits the band
     * rather than leaking the brief. Values prefer the drafted promise-framed lines (falling back to
     * the client's short §1 labels); differentiators reuse the home why-us cards; the credibility
     * badges are AUDIENCE-ordered (commercial leads certifications, homeowner leads reviews). About is
     * brand-critical and review-gated, so the voice-expanded prose is operator-checked before publish.
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeAbout(Content $content, array $slots, array $images, PageContext $ctx, bool $preview): string
    {
        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->first();

        $site = $this->site($content);

        return $this->composer->composeAbout(
            slots: $slots,
            images: $images,
            ctx: $ctx,
            story: $this->storyParagraphs($this->slotString($slots, 'our_story') ?: (string) ($narrative->story ?? '')),
            mission: $this->slotString($slots, 'mission'),
            values: $this->aboutValues($slots, $narrative),
            differentiators: $this->differentiators($content),
            team: $this->teamMembers($narrative),
            // includeCertifications: About's strip is its only credentials surface, so the captured
            // narrative certifications join the badge pool (guided intake creates no ProofItems).
            credibilityBadges: $this->credibilityBadges($content, audience: $this->audience($content), includeCertifications: true),
            trustStats: $this->trustStats($content),
            brand: trim((string) ($site->brand_name ?? '')),
            preview: $preview,
        );
    }

    /**
     * The About values — the DRAFTED promise-framed lines when the page has been generated (the
     * drafter phrases each captured value as a client promise), else the client's own short §1 labels
     * ({title, description}, safe display text — unlike the mission's long-text brief). A drafted line
     * may arrive as "Title — line" / "Title: line" or as a {title, description} map.
     *
     * @param  array<string, mixed>  $slots
     * @return list<array{title: string, description: string}>
     */
    private function aboutValues(array $slots, ?SiteNarrative $narrative): array
    {
        $raw = $slots['values'] ?? [];
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (is_array($item)) {
                $title = trim((string) ($item['title'] ?? ''));
                if ($title !== '') {
                    $out[] = ['title' => $title, 'description' => trim((string) ($item['description'] ?? $item['text'] ?? ''))];
                }

                continue;
            }

            $line = trim((string) $item);
            if ($line === '') {
                continue;
            }
            // "Title — line" / "Title: line" → {title, description}; a bare line is title-only.
            $parts = preg_split('/\s+—\s+|\s+–\s+|:\s+/', $line, 2) ?: [$line];
            $out[] = ['title' => trim($parts[0]), 'description' => trim($parts[1] ?? '')];
        }

        if ($out !== []) {
            return array_slice($out, 0, 6);
        }

        return $this->titleDescList(is_array($narrative?->values) ? $narrative->values : []);
    }

    /**
     * The site's primary audience, resolved from the active voice profile's interview — 'commercial'
     * when the captured audience reads business-facing, else 'homeowner' (the residential default).
     * Drives EMPHASIS ONLY (which trust signals lead); data still decides what renders.
     */
    private function audience(Content $content): string
    {
        $voice = VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('status', VoiceStatus::Active->value)
            ->first();

        $primary = (string) data_get($voice?->audience, 'primary', '');

        return preg_match('/commercial|business|facilit|property manager|office|industrial|building owner/i', $primary) === 1
            ? 'commercial'
            : 'homeowner';
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
     * With an $audience, the badge ORDER follows who the page is convincing (and review aggregates
     * join the pool): a homeowner is moved by social proof first (reviews → guarantees → licensed);
     * a commercial buyer by qualifications first (certifications → licenses → track record). Audience
     * sets the intended emphasis only — data still decides what actually renders (a homeowner site
     * with no reviews simply leads with its next available signal).
     *
     * With $includeCertifications, the tenant's captured narrative CERTIFICATIONS join the pool as
     * cert-typed labels (deduped) — the guided flow captures credentials there and creates no
     * ProofItems, so without this fold a guided tenant's strip could never activate. Used by pages
     * whose strip is the only credentials surface (About); pages with a certifications ROW (Home /
     * Why-Choose-Us) keep the proof-only pool so the same label never shows twice.
     *
     * @param  'homeowner'|'commercial'|null  $audience  null = the default created-at order (home / why-us callers)
     * @return list<string>
     */
    private function credibilityBadges(Content $content, ?string $audience = null, bool $includeCertifications = false): array
    {
        $types = [
            ProofType::License->value, ProofType::Cert->value, ProofType::Award->value,
            ProofType::Affiliation->value, ProofType::Warranty->value, ProofType::Guarantee->value,
        ];
        if ($audience !== null) {
            $types[] = ProofType::ReviewAggregate->value; // reviews belong in About's trust order
        }

        $items = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('is_substantiated', true)
            ->whereIn('type', $types)
            ->orderBy('created_at')
            ->get();

        // {label, type} pool: substantiated proof + (optionally) the captured certifications, deduped.
        $pool = [];
        foreach ($items as $item) {
            $label = $this->payloadString($item, ['label', 'text']);
            if ($label !== '') {
                $pool[] = ['label' => $label, 'type' => $item->type->value];
            }
        }
        if ($includeCertifications) {
            $seen = array_map(fn (array $b): string => mb_strtolower($b['label']), $pool);
            foreach ($this->certifications($content) as $cert) {
                if ($cert['label'] !== '' && ! in_array(mb_strtolower($cert['label']), $seen, true)) {
                    $pool[] = ['label' => $cert['label'], 'type' => ProofType::Cert->value];
                }
            }
        }

        if ($audience !== null) {
            $priority = $audience === 'commercial'
                ? [ProofType::Cert->value, ProofType::License->value, ProofType::Award->value, ProofType::Affiliation->value, ProofType::Warranty->value, ProofType::Guarantee->value, ProofType::ReviewAggregate->value]
                : [ProofType::ReviewAggregate->value, ProofType::Guarantee->value, ProofType::Warranty->value, ProofType::License->value, ProofType::Cert->value, ProofType::Affiliation->value, ProofType::Award->value];

            usort($pool, function (array $a, array $b) use ($priority): int {
                $rank = fn (string $t): int => ($r = array_search($t, $priority, true)) === false ? count($priority) : (int) $r;

                return $rank($a['type']) <=> $rank($b['type']);
            });
        }

        return array_slice(array_column($pool, 'label'), 0, 4);
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
     * The single credentials band's items — the tenant's captured {@see certifications()} PLUS their
     * substantiated proof {@see credibilityBadges()} (licenses / certs / ratings), folded in as
     * label-only credentials and DEDUPED against the captured certs by label. This unifies what used to
     * be two overlapping bands (the credibility strip + the certifications row) into one, so Home and
     * Why-Choose-Us never show the same trust signals twice. Verbatim; never fabricated; capped.
     *
     * @return list<array{label: string, number: string, logo_url: string}>
     */
    private function mergedCredentials(Content $content): array
    {
        $certs = $this->certifications($content);

        $seen = [];
        foreach ($certs as $cert) {
            $seen[mb_strtolower($cert['label'])] = true;
        }

        foreach ($this->credibilityBadges($content) as $badge) {
            $key = mb_strtolower(trim($badge));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $certs[] = ['label' => $badge, 'number' => '', 'logo_url' => ''];
        }

        return array_slice($certs, 0, 8);
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
