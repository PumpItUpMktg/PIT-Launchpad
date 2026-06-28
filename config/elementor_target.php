<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Elementor target profile (live-shape reconciliation)
    |--------------------------------------------------------------------------
    |
    | The wireframe library (database/data/wireframe-library) is a READ-ONLY,
    | generated input authored to Elementor's documented 0.4 container schema. The
    | live target (the companion site's Elementor 4.1.3 + Pro) drifts from that in a
    | few spots. Rather than edit the regen-fragile library, the deltas live HERE and
    | are applied by App\PageBuilder\Library\TargetNormalizer on load, so a library
    | regen can never overwrite a reconciliation.
    |
    | Discover these facts from the live target (a real export), never by assumption
    | — the #110 lesson. So far: the nested-accordion shape is export-verified; the
    | container/heading/text/image/button keys are session-captured.
    |
    */

    // The envelope the composer stamps. NOTE: production is a `_elementor_data`
    // post-meta write (not a Library import), so `version` does not gate rendering —
    // it is here only for any import/export use. The composer writes the content tree.
    'envelope' => [
        'version' => env('LP_ELEMENTOR_VERSION', '0.4'),
        'type' => 'container',
    ],

    // FAQ: the library ships the classic `accordion` (settings.tabs[]); the
    // verified-live 4.1.3 widget is `nested-accordion` (settings.items[] titles +
    // one index-paired, locked child container per item → inner column container →
    // text-editor). normalize-on-load performs this transform.
    'faq_widget' => 'nested-accordion',

    // Widgets with no verified-live shape yet (not on core service/location bodies);
    // verify before the contact/blog page types adopt their blocks.
    'unverified_widgets' => ['form', 'share-buttons', 'social-icons', 'theme-post-content', 'posts', 'nav-menu'],

    // Brand-system Phase 1: the library bakes per-block `padding` on every `wf-block`
    // container. The companion's base wf-* stylesheet drives section density from the
    // chosen structure preset (--wf-pad-block / --wf-section-gap), so the baked padding
    // is stripped on load — otherwise Bold-dense / Warm-comfortable can't differentiate.
    // Structure owns density; the block keeps only its internal flex_gaps.
    'strip_block_padding' => true,

    // Static section headings (design chrome, not §3a-fed): the AUTHORITATIVE real
    // label per `wf-*-heading` hook. The injector sets these (overriding the library
    // default, which can be scaffolding like "Problem & solution heading"). A kept
    // heading NOT listed here is treated as scaffolding and hidden — so add a page's
    // section headings here as it migrates. Audited against the service blocks.
    'static_headings' => [
        'wf-ps-heading' => 'The problem — and the fix',     // library default was scaffolding
        'wf-why-heading' => 'Why choose us',
        'wf-reviews-heading' => 'What customers say',
        'wf-faq-heading' => 'Frequently asked questions',
        'wf-cta-heading' => 'Ready to get started?',
        // Hub (service-hub) section headings.
        'wf-intro-heading' => 'About this service',
        'wf-svcgrid-heading' => 'Services in this category',
    ],

];
