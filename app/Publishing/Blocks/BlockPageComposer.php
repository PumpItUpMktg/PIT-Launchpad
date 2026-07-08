<?php

namespace App\Publishing\Blocks;

/**
 * Composes a page's `post_content` as core Gutenberg block markup from its resolved slots — the
 * Elementor→Gutenberg pivot's replacement for `_elementor_data` assembly. Orders the mockup's
 * {@see BlockSections} per page type; the block theme's active `theme.json` variation styles the
 * result. Pure: slots/images/cards/context in, block markup out — resolution (child pages, phone,
 * emergency) happens upstream so this stays testable and deterministic.
 *
 * This pass composes the HOME page (the mockup's subject: hero → services-grid → proof → CTA). Other
 * page types reuse the same section builders in their own order and land next.
 */
final class BlockPageComposer
{
    public function __construct(private readonly BlockSections $sections) {}

    /**
     * @param  array<string, mixed>  $slots  the page's resolved slot_payload
     * @param  array<string, array<string, mixed>>  $images  image map keyed by slot
     * @param  list<array{title: string, blurb: string, url: string}>  $serviceCards  resolved child service pages (real internal links)
     * @param  list<array{value?: string, label?: string}>  $trustStats  substantiated proof stats (never fabricated) for the hero trust row
     * @param  list<string>  $credibilityBadges  substantiated trust badges (licensed/certified/rated) for the credibility strip
     * @param  list<array{title?: string, description?: string}>  $differentiators  Why-Choose-Us items (site narrative)
     * @param  list<array{quote: string, author?: string, role?: string, stars?: int}>  $testimonials  substantiated reviews (data-gated)
     * @param  list<string>  $serviceAreaCounties  named counties served (the pipe-separated line beneath the block)
     * @param  list<array{county: string, cities: list<array{label: string, url: string}>}>  $serviceAreasByCounty  major cities grouped by county (the 50/50 block's cities column)
     * @param  list<array{title: string, description: string}>  $processSteps  the tenant's captured process (else a safe default)
     * @param  list<array{label?: string, number?: string, logo_url?: string}>  $certifications  real credentials (verbatim, data-gated, per-item)
     * @param  array{name?: string, description?: string}  $guarantee  the tenant's guarantee/warranty (verbatim, data-gated)
     * @param  bool  $serviceAreaMapAvailable  whether the tenant has map geometry (served counties / geocoded
     *                                         towns) — when true the areas section leads with the interactive
     *                                         map; the geometry itself travels on the blob, not in this markup.
     * @param  bool  $preview  two contexts, one rule: preview (operator proof) builds ALL recommended
     *                         sections — a data-gated section with no data renders a LABELED example
     *                         placeholder so the operator sees the whole page and what's still missing;
     *                         publish (default false) keeps the data-gating — empty sections are omitted
     *                         and a placeholder can never reach the live, visitor-facing page.
     */
    public function composeHome(
        array $slots,
        array $images,
        array $serviceCards,
        PageContext $ctx,
        array $trustStats = [],
        array $credibilityBadges = [],
        array $differentiators = [],
        array $testimonials = [],
        array $serviceAreaCounties = [],
        array $serviceAreasByCounty = [],
        array $processSteps = [],
        array $certifications = [],
        array $guarantee = [],
        bool $preview = false,
        bool $serviceAreaMapAvailable = false,
    ): string {
        $hero = $this->sections->hero(
            eyebrow: $this->str($slots['service_area'] ?? ''),
            headline: $this->str($slots['hero_headline'] ?? ''),
            subhead: $this->str($slots['hero_subhead'] ?? $slots['intro'] ?? ''),
            imageUrl: $this->imageUrl('hero_image', $images),
            imageAlt: $this->imageAlt('hero_image', $images),
            assessmentText: 'Get a free assessment',
            assessmentUrl: '#contact',
            trust: $this->heroTrust($ctx, $trustStats),
            ctx: $ctx,
        );

        // 2. Credibility strip — substantiated badges only; hides when none exist (a labeled example
        //    placeholder stands in for preview, never for publish).
        $credibility = $this->sections->credibilityStrip(lead: '', badges: $credibilityBadges, preview: $preview);

        // 2b. Certifications / trust row — real credentials near the top (data-gated, per-item, verbatim).
        $certs = $this->sections->certificationsRow($certifications, $preview);

        // 5b. Guarantee band — the tenant's guarantee as a standout promise (data-gated, verbatim).
        $guaranteeBand = $this->sections->guaranteeBand(
            name: (string) ($guarantee['name'] ?? ''),
            description: (string) ($guarantee['description'] ?? ''),
            preview: $preview,
        );

        $services = $this->sections->servicesGrid(
            eyebrow: 'What we do',
            heading: 'Our services',
            cards: $serviceCards,
        );

        // 4. Why Choose Us — real differentiators; hides when none captured (preview → example band).
        $why = $this->sections->whyChooseUs(
            eyebrow: 'Why choose us',
            heading: 'What sets us apart',
            items: $differentiators,
            preview: $preview,
        );

        // 5. How It Works — the tenant's real process when captured, else a safe business-agnostic
        //    default. Always renders.
        $process = $this->sections->howItWorks(
            eyebrow: 'How it works',
            heading: 'Getting started is simple',
            steps: $processSteps,
        );

        $proof = $this->sections->proofGallery(
            eyebrow: 'Proof',
            heading: 'Show the work you’re proud of',
            imageUrls: $this->galleryUrls($images),
        );

        // 7. Testimonials — data-gated on substantiated reviews (preview → labeled example reviews).
        $reviews = $this->sections->testimonials(
            eyebrow: 'What clients say',
            heading: 'In their words',
            quotes: $testimonials,
            preview: $preview,
        );

        // 8. Service Areas — a 50/50 map | major-cities-by-county block, county list beneath.
        //    Data-gated on real coverage (preview → example territory).
        $areas = $this->sections->serviceAreas(
            eyebrow: 'Where we work',
            heading: 'Areas we serve',
            counties: $serviceAreaCounties,
            byCounty: $serviceAreasByCounty,
            preview: $preview,
            mapAvailable: $serviceAreaMapAvailable,
        );

        $cta = $this->sections->cta(
            heading: 'Ready to get started?',
            body: 'Get in touch for a free, no-obligation assessment.',
            actionText: 'Schedule an assessment',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        // Certs reinforce credibility near the top; the guarantee lands mid-page after Why Choose Us.
        return $this->join([$hero, $credibility, $certs, $services, $why, $guaranteeBand, $process, $proof, $reviews, $areas, $cta]);
    }

    /**
     * Composes the WHY CHOOSE US page — a dedicated trust-conversion page whose body IS the
     * differentiators, reinforced by the guarantee, real credentials, and client voice. It reuses the
     * home section builders in a differentiators-led order (no services grid / process / gallery here —
     * this page argues *why*, it doesn't catalog *what*). Same two-contexts rule as the home composer:
     * preview builds every recommended section with a labeled placeholder; publish data-gates.
     *
     * @param  array<string, mixed>  $slots  the page's resolved slot_payload (hero headline/subhead)
     * @param  array<string, array<string, mixed>>  $images  image map keyed by slot
     * @param  list<array{title?: string, description?: string}>  $differentiators  the real captured value props (the page's spine)
     * @param  list<string>  $credibilityBadges  substantiated trust badges (licensed/certified/rated)
     * @param  array{name?: string, description?: string}  $guarantee  the tenant's guarantee/warranty (verbatim, data-gated)
     * @param  list<array{label?: string, number?: string, logo_url?: string}>  $certifications  real credentials (verbatim, data-gated, per-item)
     * @param  list<array{quote: string, author?: string, role?: string, stars?: int}>  $testimonials  substantiated reviews (data-gated)
     * @param  list<array{value?: string, label?: string}>  $trustStats  substantiated proof stats for the hero trust row
     * @param  bool  $preview  operator proof-view (all sections + labeled placeholders) vs publish (data-gated)
     */
    public function composeWhyChooseUs(
        array $slots,
        array $images,
        PageContext $ctx,
        array $differentiators = [],
        array $credibilityBadges = [],
        array $guarantee = [],
        array $certifications = [],
        array $testimonials = [],
        array $trustStats = [],
        bool $preview = false,
    ): string {
        $hero = $this->sections->hero(
            eyebrow: 'Why choose us',
            headline: $this->str($slots['hero_headline'] ?? '') ?: 'Why clients choose us',
            subhead: $this->str($slots['hero_subhead'] ?? $slots['intro'] ?? ''),
            imageUrl: $this->imageUrl('hero_image', $images),
            imageAlt: $this->imageAlt('hero_image', $images),
            assessmentText: 'Get a free assessment',
            assessmentUrl: '#contact',
            trust: $this->heroTrust($ctx, $trustStats),
            ctx: $ctx,
        );

        $credibility = $this->sections->credibilityStrip(lead: '', badges: $credibilityBadges, preview: $preview);

        // The page's spine: the real, captured differentiators (preview → labeled example band).
        $why = $this->sections->whyChooseUs(
            eyebrow: 'What sets us apart',
            heading: 'Reasons clients choose us',
            items: $differentiators,
            preview: $preview,
        );

        $guaranteeBand = $this->sections->guaranteeBand(
            name: (string) ($guarantee['name'] ?? ''),
            description: (string) ($guarantee['description'] ?? ''),
            preview: $preview,
        );

        $certs = $this->sections->certificationsRow($certifications, $preview);

        $reviews = $this->sections->testimonials(
            eyebrow: 'What clients say',
            heading: 'In their words',
            quotes: $testimonials,
            preview: $preview,
        );

        $cta = $this->sections->cta(
            heading: 'Ready to get started?',
            body: 'Get in touch for a free, no-obligation assessment.',
            actionText: 'Schedule an assessment',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        return $this->join([$hero, $credibility, $why, $guaranteeBand, $certs, $reviews, $cta]);
    }

    /** @param list<string> $blocks */
    private function join(array $blocks): string
    {
        return implode("\n\n", array_values(array_filter($blocks, fn (string $b): bool => trim($b) !== '')));
    }

    /**
     * The hero trust row. Emergency (opted-in) contributes the honest "24/7 · Emergency response"
     * stat; everything else must be substantiated proof passed in — never fabricated here.
     *
     * @param  list<array{value?: string, label?: string}>  $trustStats
     * @return list<array{value: string, label: string}>
     */
    private function heroTrust(PageContext $ctx, array $trustStats): array
    {
        $row = [];
        if ($ctx->emergency && $ctx->hasPhone()) {
            $row[] = ['value' => '24/7', 'label' => 'Emergency response'];
        }
        foreach ($trustStats as $stat) {
            if (trim((string) ($stat['value'] ?? '')) !== '') {
                $row[] = ['value' => (string) $stat['value'], 'label' => (string) ($stat['label'] ?? '')];
            }
        }

        return array_slice($row, 0, 4);
    }

    /**
     * @param  array<string, array<string, mixed>>  $images
     * @return list<string>
     */
    private function galleryUrls(array $images): array
    {
        $urls = [];
        foreach (['proof_image', 'gallery'] as $key) {
            $url = $this->imageUrl($key, $images);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function str(mixed $value): string
    {
        return trim(is_array($value) ? (string) ($value[0] ?? '') : (string) $value);
    }

    /**
     * @param  array<string, array<string, mixed>>  $images
     */
    private function imageUrl(string $key, array $images): ?string
    {
        $url = $images[$key]['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $images
     */
    private function imageAlt(string $key, array $images): string
    {
        return (string) ($images[$key]['alt'] ?? '');
    }
}
