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

        // Certifications / trust row — the single credentials band (data-gated, per-item, verbatim),
        //    placed LOW on the page (see the join order below). It carries BOTH the tenant's captured
        //    certifications AND their substantiated proof credentials (merged upstream), so the page shows
        //    one trust band, never two overlapping ones.
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

        // cta1 (PUSHY) — a bold accent band that blatantly asks for the business, placed MID-page (after
        // the process, once the visitor has seen what we do and how) and buffered by light sections on
        // both sides so no two colored bands ever sit adjacent.
        $ctaBold = $this->sections->cta(
            heading: 'Ready to get it fixed?',
            body: 'Get a fast, free, no-obligation quote today.',
            actionText: 'Get a free quote',
            actionUrl: '#contact',
            ctx: $ctx,
            bold: true,
        );

        // cta2 (SOFT) — the gentle closing section: info-seeking language, no pressure.
        $cta = $this->sections->cta(
            heading: 'Have a question first?',
            body: 'Tell us what you need and we’ll get right back to you — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        // Ordered for background rhythm: the two dark/accent bands (why, the pushy CTA) are each held
        // apart by a light section, and the risk-reversal cluster — guarantee (accent) then the
        // credentials/licensing row — sits LOW, just before the soft closing CTA. No two colored bands
        // are ever adjacent: D·L·D·L·C·L·L·L·C·L·D.
        return $this->join([$hero, $services, $why, $process, $ctaBold, $proof, $reviews, $areas, $guaranteeBand, $certs, $cta]);
    }

    /**
     * Composes a SERVICE page — the Elementor→blocks migration's first non-standard page type. The
     * conversion arc: hero (the customer's problem as H1 → the outcome) → a pushy CTA → the
     * problem/solution overview → the "what's included" feature checklist → the how-it-works process →
     * the grounded "why us" → client voice → FAQ → the real contact details → a soft closing CTA. Every
     * body section is drafter-generated or §1-resolved upstream; the same two-contexts rule applies
     * (preview builds all sections with labeled placeholders; publish data-gates). Reuses the shared
     * section builders so a service page is styled by the same theme.json variation as every other page.
     *
     * @param  array<string, mixed>  $slots  the page's resolved slot_payload (hero_problem/hero_solution/problem_explainer/solution_overview/service_features/why_us)
     * @param  array<string, array<string, mixed>>  $images  image map keyed by slot (hero_image)
     * @param  list<string>  $features  the drafted service-feature lines (SlotShaper-flattened)
     * @param  list<string>  $overview  the problem→solution body paragraphs (drafter HTML cleaned upstream)
     * @param  list<string>  $whyUs  the grounded "why us" paragraphs (from substantiated proof; data-gated)
     * @param  list<array{value?: string, label?: string}>  $trustStats  substantiated proof stats for the hero trust row
     * @param  list<array{title: string, description: string}>  $processSteps  the tenant's captured process (else a safe default)
     * @param  list<array{quote: string, author?: string, role?: string, stars?: int}>  $testimonials  substantiated reviews (data-gated)
     * @param  list<array{question?: string, answer?: string}>  $faqs  the drafted question/answer pairs
     * @param  string|null  $email  the business email (from §1), or null
     * @param  string|null  $address  the business address (from §1), or null
     * @param  list<array{label: string, value: string}>  $hours  per-day hours rows
     * @param  bool  $preview  operator proof-view (all sections + placeholders) vs publish (data-gated)
     */
    public function composeService(
        array $slots,
        array $images,
        PageContext $ctx,
        array $features = [],
        array $overview = [],
        array $whyUs = [],
        array $trustStats = [],
        array $processSteps = [],
        array $testimonials = [],
        array $faqs = [],
        ?string $email = null,
        ?string $address = null,
        array $hours = [],
        bool $preview = false,
    ): string {
        $hero = $this->sections->hero(
            eyebrow: $this->str($slots['service_area'] ?? '') ?: 'Our services',
            // hero_problem is the H1 (the customer's problem in their words); hero_solution the subhead.
            headline: $this->str($slots['hero_problem'] ?? $slots['hero_headline'] ?? ''),
            subhead: $this->str($slots['hero_solution'] ?? $slots['hero_subhead'] ?? $slots['intro'] ?? ''),
            imageUrl: $this->imageUrl('hero_image', $images),
            imageAlt: $this->imageAlt('hero_image', $images),
            assessmentText: 'Get a free quote',
            assessmentUrl: '#contact',
            trust: $this->heroTrust($ctx, $trustStats),
            ctx: $ctx,
        );

        // cta1 (PUSHY) placed after the overview + features (once the visitor has seen the problem, the
        // fix, and what's included) and buffered by light sections so it never sits against the hero.
        $ctaBold = $this->sections->cta(
            heading: 'Ready to get it fixed?',
            body: 'Get a fast, free, no-obligation quote today.',
            actionText: 'Get a free quote',
            actionUrl: '#contact',
            ctx: $ctx,
            bold: true,
        );

        // The problem→solution explainer (both drafted body slots, cleaned to paragraphs upstream).
        $overviewBlock = $this->sections->prose(
            eyebrow: 'Overview',
            heading: 'How we solve it',
            paragraphs: $overview,
            surface: false,
            preview: $preview,
            activates: 'appears when the page explainer is drafted',
        );

        // "What's included" — the drafted service features as a check-marked grid.
        $featuresBlock = $this->sections->featuresList(
            eyebrow: 'What we do',
            heading: 'What’s included',
            features: $features,
            preview: $preview,
        );

        // How it works — the tenant's real process when captured, else a safe business-agnostic default.
        $process = $this->sections->howItWorks(
            eyebrow: 'How it works',
            heading: 'Getting started is simple',
            steps: $processSteps,
        );

        // Grounded "why us" — written only from the substantiated-claims set (data-gated on real proof).
        $why = $this->sections->prose(
            eyebrow: 'Why choose us',
            heading: 'Why clients choose us',
            paragraphs: $whyUs,
            surface: true,
            preview: $preview,
            activates: 'appears when you add substantiated proof',
        );

        $reviews = $this->sections->testimonials(
            eyebrow: 'What clients say',
            heading: 'In their words',
            quotes: $testimonials,
            preview: $preview,
        );

        $faq = $this->sections->faqAccordion(
            eyebrow: 'Answers',
            heading: 'Common questions',
            intro: '',
            items: $faqs,
            preview: $preview,
        );

        $details = $this->sections->contactDetails(
            eyebrow: 'Reach us',
            heading: 'Get in touch',
            phoneDisplay: $ctx->phoneDisplay,
            phoneTel: $ctx->phoneTel,
            email: $email,
            address: $address,
            hours: $hours,
            preview: $preview,
        );

        // cta2 (SOFT) — the gentle closing section.
        $cta = $this->sections->cta(
            heading: 'Have a question first?',
            body: 'Tell us what you need and we’ll get right back to you — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        // Ordered for background rhythm: the hero (dark) leads into the light explainer + features, the
        // pushy CTA (accent) lands mid-page buffered by light sections, and the soft CTA (dark) closes.
        // No two colored bands are ever adjacent: D·L·L·C·L·L·L·L·L·D.
        return $this->join([$hero, $overviewBlock, $featuresBlock, $ctaBold, $process, $why, $reviews, $faq, $details, $cta]);
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

        // The page's spine: the real, captured differentiators (preview → labeled example band). Rendered
        // on the LIGHT skin here — as the page's main content it reads better light, and it keeps the
        // rhythm balanced (the dark hero is followed by a light spine, not a second dark band).
        $why = $this->sections->whyChooseUs(
            eyebrow: 'What sets us apart',
            heading: 'Reasons clients choose us',
            items: $differentiators,
            preview: $preview,
            dark: false,
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

        // cta1 (PUSHY) after the differentiators; cta2 (SOFT) closes the page.
        $ctaBold = $this->sections->cta(
            heading: 'Ready to get it fixed?',
            body: 'Get a fast, free, no-obligation quote today.',
            actionText: 'Get a free quote',
            actionUrl: '#contact',
            ctx: $ctx,
            bold: true,
        );

        $cta = $this->sections->cta(
            heading: 'Have a question first?',
            body: 'Tell us what you need and we’ll get right back to you — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        // Ordered for background rhythm — the pushy CTA and the guarantee (both accent) are each held
        // apart by a light section, and the guarantee + credentials/licensing cluster sits LOW, just
        // before the soft closing CTA: D·L·C·L·C·L·D. No two colored bands are ever adjacent.
        return $this->join([$hero, $why, $ctaBold, $reviews, $guaranteeBand, $certs, $cta]);
    }

    /**
     * Composes the ABOUT page — the trust-conversion brand narrative. About answers ONE question
     * ("can I trust these people?"), so the arc is trust-first, not autobiography: hero (positioning
     * headline + the strongest real-photo slot on the site) → story → mission → values (client
     * promises) → the why-us differentiator CARDS (reusing the home pattern — brand coherence + visual
     * rhythm, not buried text) → audience-ordered credibility → team → ONE soft consultative CTA
     * (About intent is evaluating, not emergency — no dual-CTA circus). Story/mission/values come from
     * the drafter (voice-expanded prose, resolved upstream — the mission NEVER falls back to the raw
     * intake brief); team from §1 intake. Same two-contexts rule: preview builds every recommended
     * section with a labeled placeholder; publish data-gates — a thin-intake tenant degrades to a lean,
     * honest About (hero + why-us + credibility + CTA), never a padded one.
     *
     * @param  array<string, mixed>  $slots  hero headline/subhead (+ hero image via $images)
     * @param  array<string, array<string, mixed>>  $images  image map keyed by slot
     * @param  list<string>  $story  the brand-story paragraphs (already cleaned to plain text)
     * @param  string  $mission  the DRAFTED mission statement (composed prose only — data-gated)
     * @param  list<array{title?: string, description?: string}>  $values  promise-framed values (drafted, else the client's labels)
     * @param  list<array{title?: string, description?: string}>  $differentiators  the why-us cards (site narrative)
     * @param  list<array{name?: string, role?: string, bio?: string, photo_url?: string}>  $team  the real team (gated)
     * @param  list<string>  $credibilityBadges  substantiated trust badges, audience-ordered upstream
     * @param  list<array{value?: string, label?: string}>  $trustStats  substantiated proof stats for the hero
     * @param  string  $brand  the brand name — grounds the fallback hero headline (real data, no invented positioning)
     * @param  bool  $preview  operator proof-view (all sections + placeholders) vs publish (data-gated)
     */
    public function composeAbout(
        array $slots,
        array $images,
        PageContext $ctx,
        array $story = [],
        string $mission = '',
        array $values = [],
        array $differentiators = [],
        array $team = [],
        array $credibilityBadges = [],
        array $trustStats = [],
        string $brand = '',
        bool $preview = false,
    ): string {
        $hero = $this->sections->hero(
            eyebrow: 'About us',
            // The drafted positioning headline; the fallback stays factual — the brand's real name,
            // never an invented value claim.
            headline: $this->str($slots['hero_headline'] ?? '') ?: ($brand !== '' ? 'The people behind '.$brand : 'The people behind the work'),
            subhead: $this->str($slots['hero_subhead'] ?? ''),
            imageUrl: $this->imageUrl('hero_image', $images),
            imageAlt: $this->imageAlt('hero_image', $images),
            assessmentText: 'Get in touch',
            assessmentUrl: '#contact',
            trust: $this->heroTrust($ctx, $trustStats),
            ctx: $ctx,
        );

        // The story renders as a split when a story photo exists — prose beside the photo rail. The
        // rail may carry an AI stand-in (allow_fabrication) until the client uploads real photos; the
        // proof-view marks that loudly (real team photos are the strongest trust content on the site).
        $storyImages = [];
        foreach (['story_image'] as $key) {
            $url = $this->imageUrl($key, $images);
            if ($url !== null) {
                $storyImages[] = ['url' => $url, 'alt' => $this->imageAlt($key, $images)];
            }
        }

        $storyBlock = $this->sections->story('Our story', 'Who we are', $story, $preview, $storyImages);
        $missionBlock = $this->sections->statementBand($mission, $preview);
        $valuesBlock = $this->sections->valuesGrid('What we value', 'Our promises to you', $values, $preview);

        // The differentiators as VISUAL CARDS — the same dark why-us band the home page uses (brand
        // coherence), giving About its mid-page rhythm anchor instead of burying the value props in text.
        $why = $this->sections->whyChooseUs(
            eyebrow: 'Why choose us',
            heading: 'What sets us apart',
            items: $differentiators,
            preview: $preview,
        );

        $credibility = $this->sections->credibilityStrip(lead: '', badges: $credibilityBadges, preview: $preview);
        $teamBlock = $this->sections->teamGrid('Our team', 'The people behind the work', $team, $preview);

        // ONE CTA — soft and consultative (an About visitor is evaluating, not in an emergency).
        $cta = $this->sections->cta(
            heading: 'Have a question first?',
            body: 'Tell us what you need and we’ll get right back to you — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        // Rhythm: D·L·Ls·L·D·Ls·L·D — the dark why-us band anchors mid-page; the surface bands
        // (mission, credibility) buffer it so no two colored bands ever sit adjacent.
        return $this->join([$hero, $storyBlock, $missionBlock, $valuesBlock, $why, $credibility, $teamBlock, $cta]);
    }

    /**
     * Composes the FAQ page — a plain, high-utility page: hero → the accordion of drafted Q&A → a soft
     * "still have a question?" CTA. The Q&A are drafter-generated (from trade + services), so the
     * accordion is the page; it data-gates like every other section (preview → labeled example pair).
     * The plugin emits the FAQPage JSON-LD from the same slot payload — schema and accordion never diverge.
     *
     * @param  array<string, mixed>  $slots  hero headline + intro (+ the faq repeater, resolved upstream)
     * @param  list<array{question?: string, answer?: string}>  $faqs  the drafted question/answer pairs
     * @param  bool  $preview  operator proof-view (accordion + placeholder) vs publish (data-gated)
     */
    public function composeFaq(array $slots, PageContext $ctx, array $faqs = [], bool $preview = false): string
    {
        // HERO HONESTY: the drafted intro invites the reader to scroll the answers below — if the
        // accordion data-gates out on publish (no drafted Q&A), that promise must not ship over an
        // empty page. The subhead then swaps to an honest ask-us-directly line instead.
        $subhead = $faqs === [] && ! $preview
            ? 'Have a question? Get in touch and we’ll give you a straight answer.'
            : $this->str($slots['intro'] ?? $slots['hero_subhead'] ?? '');

        $hero = $this->sections->hero(
            eyebrow: 'FAQ',
            headline: $this->str($slots['hero_headline'] ?? '') ?: 'Frequently asked questions',
            subhead: $subhead,
            imageUrl: null,
            imageAlt: '',
            assessmentText: 'Get in touch',
            assessmentUrl: '#contact',
            trust: [],
            ctx: $ctx,
        );

        $faq = $this->sections->faqAccordion(
            eyebrow: 'Answers',
            heading: 'Common questions',
            intro: '',
            items: $faqs,
            preview: $preview,
        );

        // A thin utility page carries ONE band CTA (the soft, info-seeking close) — the dark hero already
        // holds a CTA button, and a second colored band here would sit against the hero or the close with
        // no light section to separate them. Rhythm: D·L·D.
        $cta = $this->sections->cta(
            heading: 'Still have a question?',
            body: 'Get in touch and we’ll get you a straight answer — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        return $this->join([$hero, $faq, $cta]);
    }

    /**
     * Composes the AREAS WE SERVE page — the dedicated coverage hub: hero → the interactive service-area
     * block (the 50/50 map | major-cities-by-county, county list beneath) → a "don't see your town?" CTA.
     * The coverage itself is §1-derived (never invented here) and reuses the SAME {@see BlockSections::serviceAreas}
     * the home page uses — so the "believable radius" grouping is single-sourced. Data-gates like home:
     * preview shows an example territory, publish omits when there's no real coverage.
     *
     * @param  array<string, mixed>  $slots  hero headline + intro
     * @param  list<string>  $counties  named counties served (the pipe-separated line)
     * @param  list<array{county: string, cities: list<array{label: string, url: string}>}>  $byCounty  major cities grouped by county
     * @param  bool  $preview  operator proof-view (example territory) vs publish (data-gated)
     * @param  bool  $mapAvailable  whether the tenant has map geometry — leads with the interactive map when true
     */
    public function composeAreas(
        array $slots,
        PageContext $ctx,
        array $counties = [],
        array $byCounty = [],
        bool $preview = false,
        bool $mapAvailable = false,
    ): string {
        $hero = $this->sections->hero(
            eyebrow: 'Where we work',
            headline: $this->str($slots['hero_headline'] ?? '') ?: 'Areas we serve',
            subhead: $this->str($slots['intro'] ?? $slots['hero_subhead'] ?? ''),
            imageUrl: null,
            imageAlt: '',
            assessmentText: 'Get in touch',
            assessmentUrl: '#contact',
            trust: [],
            ctx: $ctx,
        );

        $areas = $this->sections->serviceAreas(
            eyebrow: 'Coverage',
            heading: 'The towns and counties we cover',
            counties: $counties,
            byCounty: $byCounty,
            preview: $preview,
            mapAvailable: $mapAvailable,
        );

        // A thin utility page carries ONE band CTA (the soft "don't see your town?" close) — the dark
        // hero already holds a CTA button, and a second colored band would leave no light section to
        // separate the colored bands. Rhythm: D·L·D.
        $cta = $this->sections->cta(
            heading: 'Don’t see your town?',
            body: 'Give us a call — if you’re nearby, chances are we cover you.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        return $this->join([$hero, $areas, $cta]);
    }

    /**
     * Composes a LEGAL page (Privacy Policy / Terms of Service) — just the {@see BlockSections::legalDocument}
     * render of a template-driven document. No marketing hero, no CTA: a legal page is a plain,
     * readable document, not a conversion surface. The drafted hero_headline (if any) overrides the
     * template title; everything else is the honest, tenant-filled boilerplate resolved upstream.
     *
     * @param  array<string, mixed>  $slots
     * @param  list<array{heading?: string, paragraphs?: list<string>}>  $sections
     */
    public function composeLegal(array $slots, string $title, string $effectiveDate, array $sections): string
    {
        $heading = $this->str($slots['hero_headline'] ?? '') ?: $title;

        return $this->sections->legalDocument($heading, $effectiveDate, $sections);
    }

    /**
     * Composes the CONTACT page — hero → the real contact details (phone / email / address / hours) →
     * a request-service CTA. The NAP is resolved from §1 upstream (the phone rides on the PageContext,
     * the same number every surface uses); nothing here is fabricated, and each channel data-gates.
     * The lead form itself is a companion-plugin shortcode (kses strips inline <form>), added separately.
     *
     * @param  array<string, mixed>  $slots  hero headline + intro
     * @param  string|null  $email  the business email (from §1), or null
     * @param  string|null  $address  the business address (from §1), or null
     * @param  list<array{label: string, value: string}>  $hours  per-day hours rows
     * @param  bool  $preview  operator proof-view (example details) vs publish (data-gated)
     */
    public function composeContact(
        array $slots,
        PageContext $ctx,
        ?string $email = null,
        ?string $address = null,
        array $hours = [],
        bool $preview = false,
    ): string {
        $hero = $this->sections->hero(
            eyebrow: 'Get in touch',
            headline: $this->str($slots['hero_headline'] ?? '') ?: 'Contact us',
            subhead: $this->str($slots['intro'] ?? $slots['hero_subhead'] ?? ''),
            imageUrl: null,
            imageAlt: '',
            assessmentText: 'Request service',
            assessmentUrl: '#contact',
            trust: [],
            ctx: $ctx,
        );

        $details = $this->sections->contactDetails(
            eyebrow: 'Reach us',
            heading: 'How to get in touch',
            phoneDisplay: $ctx->phoneDisplay,
            phoneTel: $ctx->phoneTel,
            email: $email,
            address: $address,
            hours: $hours,
            preview: $preview,
        );

        // The lead form is a preview-only placeholder for now (delivery undecided) — omitted on publish.
        $form = $this->sections->contactForm($preview);

        // A thin utility page carries ONE band CTA (the soft close) — the dark hero already holds a CTA
        // button and the NAP block is itself a call to action, so a second colored band would only stack
        // against the hero or the close. Rhythm: D·L·(L)·D.
        $cta = $this->sections->cta(
            heading: 'Prefer to just ask?',
            body: 'Tell us what you need and we’ll get right back to you — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        return $this->join([$hero, $details, $form, $cta]);
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
