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

        // Visitor-facing heading — the "show the work you're proud of" DIRECTION lives in the preview
        // placeholder note, never as the page's H2.
        $proof = $this->sections->proofGallery(
            eyebrow: 'Our work',
            heading: 'Recent work we’re proud of',
            imageUrls: $this->galleryUrls($images),
            preview: $preview,
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
     * Composes a SPOKE service page — the conversion page for one specific service (hub+spoke relay).
     * The arc: hero (H1 = the page's primary keyword — the search intent, verbatim) → the drafted
     * service intro → symptoms ("signs you need this", the intent hook) → the checked "what's
     * included" scope → the process → the cost section (factors + the honest range line ONLY when
     * the record carries one — never a blank price) → the owner-triggered comparison → a pushy CTA →
     * gated jobs/reviews (provider contracts; empty ⇒ omitted entirely, both contexts) → the related
     * services spine (hub + siblings, no cross-silo) → FAQ → the soft close. GEO-NEUTRAL by design:
     * nothing here receives or renders locality — geo lives only on location pages.
     *
     * @param  array<string, mixed>  $slots  resolved slot_payload (new keys, with old-kit fallbacks resolved upstream)
     * @param  array<string, array<string, mixed>>  $images  image map keyed by slot
     * @param  string  $keyword  the page's primary keyword — the deterministic H1 fallback
     * @param  list<string>  $intro  the drafted service-intro paragraphs
     * @param  list<string>  $symptoms  record symptoms (else the service's captured problem phrases)
     * @param  list<string>  $scopeItems  the record's "what's included" list
     * @param  list<array{title: string, description: string}>  $processSteps  record steps else tenant default
     * @param  list<string>  $costCopy  drafted honest-qualifier cost paragraphs
     * @param  list<string>  $costFactors  the record's cost factors
     * @param  string  $costRange  preformatted "$X–$Y (unit)" line, '' when the record has no range
     * @param  array{enabled?: bool, title?: string, option_a?: array{name?: string, points?: list<string>}, option_b?: array{name?: string, points?: list<string>}, verdict?: string}  $comparison
     * @param  list<array{title?: string, description?: string, photo?: string, town?: string, date?: string}>  $jobs  provider-fed (strictly gated)
     * @param  list<array{quote: string, author?: string, role?: string, stars?: int}>  $reviews  provider-fed (strictly gated)
     * @param  list<array{label: string, url: string}>  $related  hub link + sibling spokes (real permalinks)
     * @param  list<array{value?: string, label?: string}>  $trustStats  substantiated proof stats for the hero
     * @param  list<array{question?: string, answer?: string}>  $faqs  the drafted Q&A pairs
     */
    public function composeSpoke(
        array $slots,
        array $images,
        PageContext $ctx,
        string $keyword = '',
        array $intro = [],
        array $symptoms = [],
        string $symptomsIntro = '',
        array $scopeItems = [],
        string $scopeIntro = '',
        array $processSteps = [],
        array $costCopy = [],
        array $costFactors = [],
        string $costRange = '',
        array $comparison = [],
        array $jobs = [],
        array $reviews = [],
        array $related = [],
        array $trustStats = [],
        array $faqs = [],
        bool $preview = false,
        bool $hasForm = false,
    ): string {
        $hero = $this->sections->hero(
            eyebrow: 'Our services',
            // H1 = the primary keyword (the search intent) unless the drafter honestly beat it.
            headline: $this->str($slots['hero_headline'] ?? '') ?: ($keyword !== '' ? ucfirst($keyword) : 'Our service'),
            subhead: $this->str($slots['hero_subhead'] ?? ''),
            imageUrl: $this->imageUrl('hero_image', $images),
            imageAlt: $this->imageAlt('hero_image', $images),
            assessmentText: 'Get a free quote',
            assessmentUrl: '#contact',
            trust: $this->heroTrust($ctx, $trustStats),
            ctx: $ctx,
        );

        // With a lead form configured, the description row becomes a 60/40 two-column (copy + form);
        // otherwise the plain full-width prose. Same copy either way — only the layout differs.
        $introBlock = $hasForm
            ? $this->sections->proseWithForm(
                eyebrow: 'Overview',
                heading: 'What this service covers',
                paragraphs: $intro,
                preview: $preview,
            )
            : $this->sections->prose(
                eyebrow: 'Overview',
                heading: 'What this service covers',
                paragraphs: $intro,
                surface: false,
                preview: $preview,
                activates: 'appears when the page is generated',
            );

        $symptomsBlock = $this->sections->symptomsList(
            eyebrow: 'Warning signs',
            heading: 'Signs you need this',
            intro: $symptomsIntro,
            symptoms: $symptoms,
            preview: $preview,
        );

        $scope = $this->sections->featuresList(
            eyebrow: 'What we do',
            heading: 'What’s included',
            features: $scopeItems,
            preview: $preview,
        );
        if ($scopeIntro !== '' && $scope !== '') {
            // The drafted framing line rides inside the section head's slot via prose ordering —
            // simplest honest placement: a muted line directly above the checked list.
            $scope = str_replace('<!-- wp:html -->', $this->b_paragraphMuted($scopeIntro).'

'.'<!-- wp:html -->', $scope);
        }

        $process = $this->sections->howItWorks(
            eyebrow: 'How it works',
            heading: 'What to expect',
            steps: $processSteps,
        );

        $cost = $this->sections->costSection(
            eyebrow: 'Cost',
            heading: 'What it costs',
            copy: $costCopy,
            factors: $costFactors,
            rangeLine: $costRange,
            preview: $preview,
        );

        // Owner-triggered, off by default; strictly gated on enabled + complete options.
        $compare = (bool) ($comparison['enabled'] ?? false)
            ? $this->sections->comparisonTable(
                title: (string) ($comparison['title'] ?? ''),
                optionA: (array) ($comparison['option_a'] ?? []),
                optionB: (array) ($comparison['option_b'] ?? []),
                verdict: (string) ($comparison['verdict'] ?? ''),
            )
            : '';

        // The pushy mid-page ask — after the visitor has seen the scope and the honest cost story.
        $ctaBold = $this->sections->cta(
            heading: 'Ready to get it fixed?',
            body: 'Get a fast, free, no-obligation quote today.',
            actionText: 'Get a free quote',
            actionUrl: '#contact',
            ctx: $ctx,
            bold: true,
        );

        // Jobs + reviews are STRICTLY provider-gated — no preview placeholder in either context
        // (nothing an operator does today can fill them; contract-first like the location page).
        $jobsBlock = $this->sections->jobCards(
            eyebrow: 'Recent work',
            heading: 'Recent jobs like this',
            jobs: $jobs,
        );

        $reviewsBlock = $this->sections->testimonials(
            eyebrow: 'What clients say',
            heading: 'Reviews for this service',
            quotes: $reviews,
            preview: false,
        );

        $relatedBlock = $this->sections->relatedServices(
            eyebrow: 'Related services',
            heading: 'You may also need',
            links: $related,
        );

        $faq = $this->sections->faqAccordion(
            eyebrow: 'Answers',
            heading: 'Common questions',
            intro: '',
            items: $faqs,
            preview: $preview,
        );

        $cta = $this->sections->cta(
            heading: 'Have a question first?',
            body: 'Tell us what you need and we’ll get right back to you — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        // Rhythm: the pushy CTA (accent) sits mid-page buffered by light sections on both sides;
        // hero and the soft close are the only other colored bands. D·L·L·Ls·Ls·Ls·(Ls)·C·L·Ls·L·L·D.
        return $this->join([$hero, $introBlock, $symptomsBlock, $scope, $process, $cost, $compare, $ctaBold, $jobsBlock, $reviewsBlock, $relatedBlock, $faq, $cta]);
    }

    /**
     * Composes a HUB (silo pillar) page — ranks for the category keyword, routes to its spokes, and
     * holds the silo's internal-link spine. The arc: hero (H1 = category keyword) → the drafted
     * category intro → the SERVICES GRID (one card per child spoke, real links — data-bound at
     * compose time so regenerating/repushing after adding a spoke refreshes it) → why it matters →
     * the process → the trust strip (real credentials) → gated reviews (across the hub's spokes) →
     * category FAQ → one soft closing CTA. Geo-neutral like every service page.
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     * @param  string  $keyword  the category keyword — the deterministic H1 fallback
     * @param  list<string>  $intro  drafted category-intro paragraphs
     * @param  list<array{title: string, blurb: string, url: string}>  $spokeCards  one card per child spoke (real permalinks)
     * @param  list<string>  $why  drafted "why it matters" paragraphs
     * @param  list<array{title: string, description: string}>  $processSteps  tenant default process
     * @param  list<array{label?: string, number?: string, logo_url?: string}>  $certifications  real credentials (verbatim)
     * @param  list<array{quote: string, author?: string, role?: string, stars?: int}>  $reviews  provider-fed (strictly gated)
     * @param  list<array{value?: string, label?: string}>  $trustStats
     * @param  list<array{question?: string, answer?: string}>  $faqs
     */
    public function composeHub(
        array $slots,
        array $images,
        PageContext $ctx,
        string $keyword = '',
        array $intro = [],
        array $spokeCards = [],
        array $why = [],
        array $processSteps = [],
        array $certifications = [],
        array $reviews = [],
        array $trustStats = [],
        array $faqs = [],
        bool $preview = false,
    ): string {
        $hero = $this->sections->hero(
            eyebrow: 'What we do',
            headline: $this->str($slots['hero_headline'] ?? '') ?: ($keyword !== '' ? ucfirst($keyword) : 'Our services'),
            subhead: $this->str($slots['hero_subhead'] ?? ''),
            imageUrl: $this->imageUrl('hero_image', $images),
            imageAlt: $this->imageAlt('hero_image', $images),
            assessmentText: 'Get a free assessment',
            assessmentUrl: '#contact',
            trust: $this->heroTrust($ctx, $trustStats),
            ctx: $ctx,
        );

        $introBlock = $this->sections->prose(
            eyebrow: 'Overview',
            heading: 'What this covers',
            paragraphs: $intro,
            surface: false,
            preview: $preview,
            activates: 'appears when the page is generated',
        );

        // The internal-link spine: one card per child spoke, resolved fresh at compose time.
        $grid = $this->sections->servicesGrid(
            eyebrow: 'The services',
            heading: 'Choose the service you need',
            cards: $spokeCards,
        );

        $whyBlock = $this->sections->prose(
            eyebrow: 'Why it matters',
            heading: 'The cost of waiting',
            paragraphs: $why,
            surface: true,
            preview: $preview,
            activates: 'appears when the page is generated',
        );

        $process = $this->sections->howItWorks(
            eyebrow: 'How it works',
            heading: 'Getting started is simple',
            steps: $processSteps,
        );

        $certs = $this->sections->certificationsRow($certifications, $preview);

        // Reviews across the hub's spokes — strictly provider-gated, both contexts.
        $reviewsBlock = $this->sections->testimonials(
            eyebrow: 'What clients say',
            heading: 'Reviews from this work',
            quotes: $reviews,
            preview: false,
        );

        $faq = $this->sections->faqAccordion(
            eyebrow: 'Answers',
            heading: 'Common questions',
            intro: '',
            items: $faqs,
            preview: $preview,
        );

        $cta = $this->sections->cta(
            heading: 'Not sure which you need?',
            body: 'Describe the problem and we’ll point you to the right fix — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        // Routing page: hero and the close are the only colored bands (D·L·L·Ls·Ls·Ls·Ls·L·D).
        return $this->join([$hero, $introBlock, $grid, $whyBlock, $process, $certs, $reviewsBlock, $faq, $cta]);
    }

    /** A muted framing paragraph (the scope-intro line) — kept tiny and local to composeSpoke. */
    private function b_paragraphMuted(string $text): string
    {
        return '<!-- wp:paragraph {"textColor":"muted","className":"lp-scope-intro"} --><p class="lp-scope-intro has-muted-color has-text-color">'
            .htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8').'</p><!-- /wp:paragraph -->';
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
     * @param  list<string>  $credibilityBadges  substantiated trust badges, audience-ordered upstream
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
        array $credibilityBadges = [],
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

        // The audience-ordered trust signals (licensed / rated / guaranteed — order resolved upstream:
        // commercial leads certifications, homeowner leads reviews), per-item gated.
        $credibility = $this->sections->credibilityStrip(lead: '', badges: $credibilityBadges, preview: $preview);

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

        // Exactly TWO CTA bands — one MID (pushy, right after the differentiators grid so it always has
        // content above it) + one FINAL (soft close). Never CTAs wrapped around no content.
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

        // The trust arc: grid → mid CTA → credibility + certifications cluster → the guarantee promise →
        // client voice → soft close. Rhythm D·L·C·Ls·Ls·C·Ls·D — the two accent bands (mid CTA,
        // guarantee) are each buffered by light sections; no two colored bands are ever adjacent.
        return $this->join([$hero, $why, $ctaBold, $credibility, $certs, $guaranteeBand, $reviews, $cta]);
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
     * the service-area brief → a request-service CTA. Contact is pure conversion: phone-forward (for
     * home services the call IS the conversion; emergency treatment rides the shared hero/CTA gating),
     * short hero, no selling. The NAP is resolved from §1 upstream (the phone rides on the PageContext,
     * the same number every surface uses — NAP-consistent with the footer + schema); the address only
     * arrives for a real storefront (gated upstream); nothing here is fabricated, and each channel
     * data-gates. The lead form itself is a companion-plugin shortcode (kses strips inline <form>),
     * added separately once its delivery destination is configured.
     *
     * @param  array<string, mixed>  $slots  hero headline + intro
     * @param  string|null  $email  the business email (from §1), or null
     * @param  string|null  $address  the business address (from §1) — ONLY when a real storefront, or null
     * @param  list<array{label: string, value: string}>  $hours  per-day hours rows (+ the 24/7 emergency row)
     * @param  string  $serviceAreaBrief  the drafted one-line "where we work" (data-gated)
     * @param  'homeowner'|'commercial'  $audience  phrases the ask — a commercial buyer requests an assessment
     * @param  bool  $preview  operator proof-view (example details) vs publish (data-gated)
     */
    public function composeContact(
        array $slots,
        PageContext $ctx,
        ?string $email = null,
        ?string $address = null,
        array $hours = [],
        string $serviceAreaBrief = '',
        string $audience = 'homeowner',
        bool $hasForm = false,
        array $images = [],
        bool $mapAvailable = false,
        bool $storefront = false,
        bool $preview = false,
    ): string {
        $ask = $audience === 'commercial' ? 'Request an assessment' : 'Request service';

        $hero = $this->sections->hero(
            eyebrow: 'Get in touch',
            headline: $this->str($slots['hero_headline'] ?? '') ?: 'Contact us',
            subhead: $this->str($slots['intro'] ?? $slots['hero_subhead'] ?? ''),
            imageUrl: $this->imageUrl('hero_image', $images),
            imageAlt: $this->imageAlt('hero_image', $images),
            assessmentText: $ask,
            assessmentUrl: '#contact',
            trust: [],
            ctx: $ctx,
        );

        // The unmissable emergency path, directly under the hero (offers_emergency-gated in the strip).
        $emergency = $this->sections->emergencyStrip($ctx);

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

        // The MAP — a storefront centers on its pin ("Find us", the address beneath); a mobile-only
        // business shows its coverage footprint ("Where we work", the drafted brief beneath). Renders
        // only when geometry exists — never an empty box.
        $map = $this->sections->contactMap(
            eyebrow: $storefront ? 'Find us' : 'Where we work',
            heading: $storefront ? 'Come see us' : 'The area we cover',
            line: $storefront ? $address : ($serviceAreaBrief !== '' ? $serviceAreaBrief : null),
            available: $mapAvailable,
        );

        // The drafted "where we work" line — a reader self-checks coverage before calling. Folded under
        // the map when one renders (no duplicate section); standalone otherwise. Data-gated.
        $brief = $mapAvailable ? '' : $this->sections->prose(
            eyebrow: 'Where we work',
            heading: 'Our service area',
            paragraphs: $serviceAreaBrief !== '' ? [$serviceAreaBrief] : [],
            surface: false,
            preview: $preview,
            activates: 'appears when your service area is captured and the page is generated',
        );

        // With a configured GHL embed the form section is REAL (the [lp_form] shortcode renders the
        // embed server-side); without one it stays a preview-only placeholder — a form that routes
        // nowhere never ships.
        $form = $this->sections->contactForm($preview, $hasForm);

        // A thin utility page carries ONE band CTA (the soft close) — the dark hero already holds a CTA
        // button and the NAP block is itself a call to action, so a second colored band would only stack
        // against the hero or the close. Rhythm: D·Ls·L·(L)·D.
        $cta = $this->sections->cta(
            heading: 'Prefer to just ask?',
            body: 'Tell us what you need and we’ll get right back to you — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        return $this->join([$hero, $emergency, $details, $map, $brief, $form, $cta]);
    }

    /**
     * Composes a LOCATION page — one rich local landing page per GBP location (the second proven
     * page type). The arc: hero (deterministic H1 formula "{Trade} in {City}, {ST}" unless the
     * drafter honestly beat it) → the drafted local intro (grounded local facts, market notes) →
     * the services offered here (link-when-a-live-page-exists rule, resolved upstream) → coverage
     * prose from the served-towns list → local reviews and recent jobs nearby (STRICTLY gated:
     * contract-first providers, empty ⇒ the section is omitted entirely in BOTH contexts — no
     * headers over nothing, no placeholders, because no operator action can fill them yet) → the
     * local FAQ → ONE soft closing CTA carrying the LOCATION's phone (the per-location ctx).
     *
     * @param  array<string, mixed>  $slots  the resolved slot_payload (hero_headline/hero_subhead/loc_services_intro)
     * @param  array<string, array<string, mixed>>  $images  image map keyed by slot (hero_image)
     * @param  PageContext  $ctx  the PER-LOCATION context — this location's phone, else the site number
     * @param  list<string>  $intro  the drafted local-intro paragraphs (cleaned upstream)
     * @param  list<array{title: string, blurb?: string, url?: string}>  $serviceCards  catalog services; a non-empty url is a LIVE service page (the link rule)
     * @param  list<string>  $coverage  the coverage prose paragraphs (drafted, else the honest derived towns sentence)
     * @param  list<array{quote: string, author?: string, role?: string, stars?: int}>  $reviews  real local reviews (provider-fed; empty ⇒ omitted, never a placeholder)
     * @param  list<array{title?: string, description?: string, photo?: string, town?: string, date?: string}>  $jobs  real nearby jobs (provider-fed; empty ⇒ omitted, never a placeholder)
     * @param  list<array{question?: string, answer?: string}>  $faqs  the drafted local Q&A pairs
     * @param  list<array{value?: string, label?: string}>  $trustStats  substantiated proof stats for the hero trust row
     * @param  bool  $preview  operator proof-view — drafted sections show labeled placeholders; the provider-gated reviews/jobs stay strictly data-gated even here
     */
    public function composeLocation(
        array $slots,
        array $images,
        PageContext $ctx,
        string $city,
        string $state,
        string $trade = '',
        array $intro = [],
        string $servicesIntro = '',
        array $serviceCards = [],
        array $coverage = [],
        array $reviews = [],
        array $jobs = [],
        array $faqs = [],
        array $trustStats = [],
        bool $preview = false,
    ): string {
        $place = trim($city) !== '' ? (trim($state) !== '' ? trim($city).', '.trim($state) : trim($city)) : '';

        // The deterministic local H1 — "{Trade} in {City}, {ST}". The drafted headline only overrides
        // it when the drafter produced one (the kit hint tells it to draft only if it can beat the
        // formula honestly); with no trade captured the fallback stays factual ("Serving {City, ST}").
        $formula = $trade !== '' && $place !== ''
            ? ucfirst($trade).' in '.$place
            : ($place !== '' ? 'Serving '.$place : 'Our service area');

        $hero = $this->sections->hero(
            eyebrow: 'Local service',
            headline: $this->str($slots['hero_headline'] ?? '') ?: $formula,
            subhead: $this->str($slots['hero_subhead'] ?? ''),
            imageUrl: $this->imageUrl('hero_image', $images),
            imageAlt: $this->imageAlt('hero_image', $images),
            assessmentText: 'Get a free assessment',
            assessmentUrl: '#contact',
            trust: $this->heroTrust($ctx, $trustStats),
            ctx: $ctx,
        );

        // The drafted local grounding paragraphs — market notes + grounded facts woven into honest prose.
        $introBlock = $this->sections->prose(
            eyebrow: $place !== '' ? 'Working in '.$place : 'Working locally',
            heading: $city !== '' ? 'Your local team in '.$city : 'Your local team',
            paragraphs: $intro,
            surface: false,
            preview: $preview,
            activates: 'appears when the page is generated (the local intro drafts from your market notes and grounded local facts)',
        );

        $services = $this->sections->servicesGrid(
            eyebrow: 'What we do here',
            heading: $city !== '' ? 'Services in '.$city : 'Our services',
            cards: $serviceCards,
            intro: $servicesIntro,
        );

        // Coverage prose from the served-towns list (readable paragraph, never a keyword dump).
        $coverageBlock = $this->sections->prose(
            eyebrow: 'Coverage',
            heading: $city !== '' ? 'The towns we cover around '.$city : 'The towns we cover',
            paragraphs: $coverage,
            surface: true,
            preview: $preview,
            activates: 'appears when this location\'s served towns are captured',
        );

        // Reviews + jobs are STRICTLY provider-gated — preview: false is deliberate (no "Example"
        // placeholder in either context; nothing an operator does today can fill them).
        $reviewsBlock = $this->sections->testimonials(
            eyebrow: 'What neighbors say',
            heading: $city !== '' ? 'Reviews from around '.$city : 'Reviews nearby',
            quotes: $reviews,
            preview: false,
        );

        $jobsBlock = $this->sections->jobCards(
            eyebrow: 'Recent work',
            heading: $city !== '' ? 'Recent jobs near '.$city : 'Recent jobs nearby',
            jobs: $jobs,
        );

        $faq = $this->sections->faqAccordion(
            eyebrow: 'Local answers',
            heading: 'Common questions',
            intro: '',
            items: $faqs,
            preview: $preview,
        );

        // ONE soft closing CTA — its phone is the LOCATION's own number via the per-location ctx.
        $cta = $this->sections->cta(
            heading: $city !== '' ? 'Need help in '.$city.'?' : 'Need a hand?',
            body: 'Tell us what you need and we’ll get right back to you — no pressure.',
            actionText: 'Get in touch',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        // Rhythm: only the hero and the closing CTA are colored bands (D·L·L·Ls·Ls·L·L·D) — the
        // gated reviews/jobs sections dropping out never puts two colored bands adjacent.
        return $this->join([$hero, $introBlock, $services, $coverageBlock, $reviewsBlock, $jobsBlock, $faq, $cta]);
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
