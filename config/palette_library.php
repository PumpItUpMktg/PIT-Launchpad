<?php

/*
|--------------------------------------------------------------------------
| Curated palette library
|--------------------------------------------------------------------------
|
| The closed set of hand-vetted brand palettes the AI recommends FROM (it no
| longer generates raw hex). Each palette is the FULL --wf-* token set, explicit
| per scheme — no runtime color math. Curation IS the contrast enforcement: every
| set here must pass ContrastMatrix across the §3 surface model (proven by
| PaletteLibraryTest + the `launchpad:palette-library` CLI) before it ships.
|
| Tokens: the nine color slots (primary/secondary/accent/text/text_muted/bg/
| bg_alt/border/on_accent) + a heading/body font pairing from the shortlist.
| `form_affinity` (trust|bold|warm) + `industry_tags` feed the recommender.
|
| These seeds are vetted-by-eye starting points; the test + CLI certify them.
|
*/

return [

    // The deterministic fallback per scheme (when the recommender returns an
    // off-list / empty id) — must be a stable, safe set in each scheme.
    'defaults' => [
        'light' => 'slate-professional',
        'dark' => 'midnight-current',
    ],

    'palettes' => [

        // ---- Light (dark text on light bg) ----
        [
            'id' => 'deep-current',
            'name' => 'Deep Current',
            'scheme' => 'light',
            'form_affinity' => 'trust',
            'industry_tags' => ['water', 'restoration', 'plumbing', 'trades'],
            'tokens' => [
                'primary' => '#1e3a5f', 'secondary' => '#475569', 'accent' => '#b45309',
                'text' => '#0f172a', 'text_muted' => '#475569', 'bg' => '#ffffff',
                'bg_alt' => '#f1f5f9', 'border' => '#e2e8f0', 'on_accent' => '#ffffff',
            ],
            'font_heading' => 'Archivo', 'font_body' => 'Inter',
        ],
        [
            'id' => 'slate-professional',
            'name' => 'Slate Professional',
            'scheme' => 'light',
            'form_affinity' => 'trust',
            'industry_tags' => ['hvac', 'electrical', 'trades', 'professional'],
            'tokens' => [
                'primary' => '#334155', 'secondary' => '#64748b', 'accent' => '#0e7490',
                'text' => '#1e293b', 'text_muted' => '#64748b', 'bg' => '#ffffff',
                'bg_alt' => '#f8fafc', 'border' => '#e2e8f0', 'on_accent' => '#ffffff',
            ],
            'font_heading' => 'Inter', 'font_body' => 'Inter',
        ],
        [
            'id' => 'warm-brick',
            'name' => 'Warm Brick',
            'scheme' => 'light',
            'form_affinity' => 'warm',
            'industry_tags' => ['roofing', 'masonry', 'landscaping', 'local'],
            'tokens' => [
                'primary' => '#7c2d12', 'secondary' => '#92400e', 'accent' => '#c2410c',
                'text' => '#292524', 'text_muted' => '#57534e', 'bg' => '#fffbf5',
                'bg_alt' => '#f5f0e8', 'border' => '#e7e0d5', 'on_accent' => '#ffffff',
            ],
            'font_heading' => 'Fraunces', 'font_body' => 'Source Sans 3',
        ],

        // ---- Dark (light text on dark bg) ----
        [
            'id' => 'midnight-current',
            'name' => 'Midnight Current',
            'scheme' => 'dark',
            'form_affinity' => 'trust',
            'industry_tags' => ['water', 'restoration', 'plumbing', 'trades'],
            'tokens' => [
                'primary' => '#38bdf8', 'secondary' => '#64748b', 'accent' => '#22d3ee',
                'text' => '#f1f5f9', 'text_muted' => '#94a3b8', 'bg' => '#0f172a',
                'bg_alt' => '#1e293b', 'border' => '#334155', 'on_accent' => '#1a1a1a',
            ],
            'font_heading' => 'Inter', 'font_body' => 'Inter',
        ],
        [
            'id' => 'carbon',
            'name' => 'Carbon',
            'scheme' => 'dark',
            'form_affinity' => 'bold',
            'industry_tags' => ['automotive', 'fitness', 'modern', 'tech'],
            'tokens' => [
                'primary' => '#818cf8', 'secondary' => '#6b7280', 'accent' => '#f59e0b',
                'text' => '#f4f4f5', 'text_muted' => '#a1a1aa', 'bg' => '#18181b',
                'bg_alt' => '#27272a', 'border' => '#3f3f46', 'on_accent' => '#1a1a1a',
            ],
            'font_heading' => 'Sora', 'font_body' => 'Inter',
        ],
        [
            'id' => 'deep-forest',
            'name' => 'Deep Forest',
            'scheme' => 'dark',
            'form_affinity' => 'warm',
            'industry_tags' => ['landscaping', 'outdoor', 'pest', 'local'],
            'tokens' => [
                'primary' => '#34d399', 'secondary' => '#78716c', 'accent' => '#fbbf24',
                'text' => '#f5f5f4', 'text_muted' => '#a8a29e', 'bg' => '#1c1917',
                'bg_alt' => '#292524', 'border' => '#44403c', 'on_accent' => '#1c1917',
            ],
            'font_heading' => 'Bitter', 'font_body' => 'Karla',
        ],

    ],
];
