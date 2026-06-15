<?php

namespace App\Branding;

use App\Integrations\Claude\ClaudeClient;

/**
 * Generates a brand (palette + typography + rationale) from a short interview, for
 * the ~99% of clients with no existing brand kit. The prompt enforces real design
 * discipline — color theory (primary/accent contrast, a readable neutral text
 * color, WCAG-AA, industry-appropriateness), typographic pairing, and voice-driven
 * choices from the personality answer.
 *
 * CRITICAL guard: the model's chosen fonts are validated against the real loadable
 * Google Fonts catalog, and any miss/hallucination/misspelling falls back to a safe
 * default — because an unavailable family would silently break the Global Kit
 * cascade. The text color is likewise held to a WCAG-AA contrast floor. Every such
 * correction is recorded on the result so the review screen (and tests) can see it.
 *
 * Output is the {palette:{primary,accent,text}, typography:{heading,body}, rationale}
 * shape that maps straight onto SiteBranding and the BrandKitAssembler.
 */
class BrandGenerator
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly FontCatalog $fonts,
    ) {}

    public function generate(BrandBrief $brief): GeneratedBrand
    {
        $raw = $this->parse($this->claude->complete($this->prompt($brief), $this->system()));

        $adjustments = [];

        $palette = $this->validatePalette(is_array($raw['palette'] ?? null) ? $raw['palette'] : [], $adjustments);
        $typography = $this->validateTypography(is_array($raw['typography'] ?? null) ? $raw['typography'] : [], $adjustments);
        $rationale = trim((string) ($raw['rationale'] ?? ''));

        return new GeneratedBrand($palette, $typography, $rationale, $adjustments);
    }

    /**
     * Recommend one structure preset (trust|bold|warm) from the personality, with a
     * one-line rationale. The model proposes; we enforce the valid set, falling back
     * to the deterministic personality→structure map for an off-list answer. Precedes
     * palette generation so palettes are generated FOR the chosen structure.
     */
    public function recommendStructure(BrandBrief $brief): StructureRecommendation
    {
        $raw = $this->parse($this->claude->complete(
            $this->structurePrompt($brief),
            'You are a brand designer choosing a layout system. Reply STRICT JSON only.'
        ));

        $valid = ['trust', 'bold', 'warm'];
        $slug = strtolower(trim((string) ($raw['structure'] ?? '')));
        if (! in_array($slug, $valid, true)) {
            $map = (array) config('launchpad.brand.structure_for_personality', []);
            $slug = (string) ($map[$brief->personality] ?? config('launchpad.brand.default_structure', 'trust'));
        }

        $rationale = trim((string) ($raw['rationale'] ?? ''));

        return new StructureRecommendation($slug, $rationale);
    }

    /**
     * Generate N validated brand candidates for a chosen structure. Each is fully
     * guard-checked: fonts resolved against the catalog (invented → safe default,
     * flagged); the full palette filled (missing/invalid slot → safe default); the
     * contrast matrix enforced — body text auto-nudged to a readable neutral and
     * flagged, and a candidate whose accent can't carry white CTA text (the
     * un-nudgeable conversion gate) is DROPPED. Exactly one survivor is `recommended`;
     * if every candidate is dropped, a guaranteed-accessible safe candidate is
     * synthesized so the set is never empty.
     *
     * @param  list<string>  $avoid  prior palette summaries to vary from ("show me 3 more")
     */
    public function generateCandidates(BrandBrief $brief, string $structure, ?int $count = null, array $avoid = []): BrandCandidateSet
    {
        $count = $count ?? (int) config('launchpad.brand.candidate_count', 4);
        $raw = $this->parse($this->claude->complete(
            $this->candidatePrompt($brief, $structure, $count, $avoid),
            $this->candidateSystem()
        ));

        $candidates = [];
        foreach (is_array($raw['candidates'] ?? null) ? $raw['candidates'] : [] as $rawCandidate) {
            $candidate = is_array($rawCandidate) ? $this->validateCandidate($rawCandidate) : null;
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            $candidates = [$this->safeCandidate($structure)];
        }

        return new BrandCandidateSet($this->electRecommended($candidates), $structure);
    }

    /**
     * Validate one raw candidate. Returns null to DROP it (accent fails the white-CTA
     * gate — the conversion element can't be inaccessible).
     *
     * @param  array<string, mixed>  $raw
     */
    private function validateCandidate(array $raw): ?BrandCandidate
    {
        $tokens = is_array($raw['tokens'] ?? null) ? $raw['tokens'] : [];
        $fonts = is_array($raw['fonts'] ?? null) ? $raw['fonts'] : [];
        $adjustments = [];

        $palette = $this->fullPalette($tokens, $adjustments);

        // Hard gate: the accent must carry readable CTA text with its BEST (white or
        // dark) text color. Only a genuine mid-tone accent — where neither passes —
        // is dropped; a light accent is rescued by dark CTA text (below), not dropped.
        if (! ContrastMatrix::accentPassesButton($palette['accent'])) {
            return null;
        }

        // The CTA text color for this accent (white or dark) → the on-accent token the
        // stylesheet's button uses, so a light accent gets readable dark text.
        $palette['on_accent'] = ContrastMatrix::onAccent($palette['accent']);

        // Soft gate: nudge body/muted text to a readable neutral when it fails on the
        // page or alt-tint background, and record it.
        $palette = $this->nudgeText($palette, $adjustments);

        $typography = [
            'heading' => $this->validateFont('heading', (string) ($fonts['--wf-font-heading'] ?? ''), $adjustments),
            'body' => $this->validateFont('body', (string) ($fonts['--wf-font-body'] ?? ''), $adjustments),
        ];

        return new BrandCandidate(
            palette: $palette,
            typography: $typography,
            rationale: trim((string) ($raw['rationale'] ?? '')),
            recommended: (bool) ($raw['recommended'] ?? false),
            adjustments: $adjustments,
        );
    }

    /**
     * Resolve the full palette from the candidate's `--wf-color-*` tokens, filling any
     * missing/invalid slot from the configured safe palette (flagged).
     *
     * @param  array<string, mixed>  $tokens
     * @param  list<string>  $adjustments  (by ref)
     * @return array<string, string>
     */
    private function fullPalette(array $tokens, array &$adjustments): array
    {
        $safe = (array) config('launchpad.brand.safe_colors', []);
        $slots = [
            'primary' => '--wf-color-primary',
            'secondary' => '--wf-color-secondary',
            'accent' => '--wf-color-accent',
            'text' => '--wf-color-text',
            'text_muted' => '--wf-color-text-muted',
            'bg' => '--wf-color-bg',
            'bg_alt' => '--wf-color-bg-alt',
            'border' => '--wf-color-border',
        ];

        $palette = [];
        foreach ($slots as $key => $token) {
            $hex = $this->validateHex((string) ($tokens[$token] ?? ''));
            if ($hex === null) {
                $fallback = $this->validateHex((string) ($safe[$key] ?? '')) ?? '#1a1a1a';
                $adjustments[] = "Invalid or missing {$key} color — fell back to {$fallback}.";
                $hex = $fallback;
            }
            $palette[$key] = $hex;
        }

        return $palette;
    }

    /**
     * Correct body + muted text to a readable neutral when they fail the contrast
     * matrix, recording each nudge. Accent/button is gated earlier (drop, not nudge).
     *
     * @param  array<string, string>  $palette
     * @param  list<string>  $adjustments  (by ref)
     * @return array<string, string>
     */
    private function nudgeText(array $palette, array &$adjustments): array
    {
        $safe = (array) config('launchpad.brand.safe_colors', []);
        $safeText = $this->validateHex((string) ($safe['text'] ?? '#1A1A1A')) ?? '#1a1a1a';
        $safeMuted = $this->validateHex((string) ($safe['text_muted'] ?? '#5B6470')) ?? '#5b6470';

        foreach (ContrastMatrix::failures($palette) as $failure) {
            if ($failure['pair'] === 'text-on-bg' || $failure['pair'] === 'text-on-bg_alt') {
                if ($palette['text'] !== $safeText) {
                    $adjustments[] = "Body text {$palette['text']} failed WCAG-AA ({$failure['pair']}) — corrected to {$safeText}.";
                    $palette['text'] = $safeText;
                }
            } elseif ($failure['pair'] === 'text_muted-on-bg' && $palette['text_muted'] !== $safeMuted) {
                $adjustments[] = "Muted text {$palette['text_muted']} failed contrast — corrected to {$safeMuted}.";
                $palette['text_muted'] = $safeMuted;
            }
        }

        return $palette;
    }

    /**
     * Exactly one recommended candidate: keep the model's pick when it survived
     * validation, else promote the first. All others are cleared.
     *
     * @param  list<BrandCandidate>  $candidates
     * @return list<BrandCandidate>
     */
    private function electRecommended(array $candidates): array
    {
        $chosen = null;
        foreach ($candidates as $i => $candidate) {
            if ($candidate->recommended) {
                $chosen = $i;
                break;
            }
        }
        $chosen ??= 0;

        return array_map(
            fn (int $i, BrandCandidate $c) => $c->withRecommended($i === $chosen),
            array_keys($candidates),
            $candidates,
        );
    }

    /**
     * A guaranteed-accessible fallback for the chosen structure: the safe palette
     * (with its CTA text auto-chosen for the accent) + the structure's first vetted
     * font pairing, so the fallback is BOTH accessible and structure-matched. A
     * self-check asserts it clears the same gate — a misconfigured safe palette would
     * surface in tests, never ship a failing default.
     */
    private function safeCandidate(string $structure): BrandCandidate
    {
        $adjustments = ['All generated candidates were dropped by the contrast gate; using the safe default.'];
        $palette = $this->fullPalette([], $adjustments);
        $palette['on_accent'] = ContrastMatrix::onAccent($palette['accent']);

        // Self-check: the safe palette must itself pass (else the config is broken).
        if (ContrastMatrix::failures($palette) !== []) {
            $adjustments[] = 'WARNING: the configured safe palette does not pass the contrast gate — review launchpad.brand.safe_colors.';
        }

        $pairing = (array) config('launchpad.brand.font_pairings.'.$structure.'.0', []);

        return new BrandCandidate(
            palette: $palette,
            typography: [
                'heading' => (string) ($pairing['heading'] ?? config('launchpad.brand.safe_fonts.heading', 'Inter')),
                'body' => (string) ($pairing['body'] ?? config('launchpad.brand.safe_fonts.body', 'Inter')),
            ],
            rationale: 'A safe, accessible default palette — the generated candidates did not pass the accessibility gate.',
            recommended: true,
            adjustments: $adjustments,
        );
    }

    private function structurePrompt(BrandBrief $brief): string
    {
        $personality = $brief->descriptor();

        return implode("\n", [
            "Choose ONE layout structure for a {$brief->industry} business whose brand personality is: {$personality}.",
            'Options:',
            '- "trust": clean, established, flat; generous whitespace; borders define; color as accent.',
            '- "bold": confident, dense, conversion-forward; sharp corners; strong contrast; color-as-structure.',
            '- "warm": friendly, human, local; rounded corners; soft shadows; comfortable spacing.',
            'Respond with ONLY this JSON: {"structure":"trust|bold|warm","rationale":"one sentence"}',
        ]);
    }

    private function candidateSystem(): string
    {
        return 'You are a senior brand designer for local service businesses. You apply real color theory '
            .'(WCAG-AA contrast, industry-appropriateness) and typographic pairing, and you choose with intent. '
            .'You return STRICT JSON only, never prose or code fences.';
    }

    private function candidatePrompt(BrandBrief $brief, string $structure, int $count, array $avoid): string
    {
        $personality = $brief->descriptor();
        $character = [
            'trust' => 'restrained and accent-led — mostly white/light backgrounds with a hairline border color and a single warm accent; navy/steel primaries read well here.',
            'bold' => 'saturated and color-as-structure — expects a DARK or saturated bg_alt (the alternating blocks go dark), high contrast, a punchy accent.',
            'warm' => 'muted and earthy — warm off-white bg/bg_alt tints, soft, inviting, approachable.',
        ][$structure] ?? 'clean and professional.';

        $shortlist = (array) config('launchpad.brand.font_pairings.'.$structure, []);
        $pairLines = [];
        foreach ($shortlist as $pair) {
            if (is_array($pair) && isset($pair['heading'], $pair['body'])) {
                $pairLines[] = $pair['heading'].' / '.$pair['body'];
            }
        }

        $lines = [
            "Design {$count} distinct brand candidates for a {$brief->industry} business.",
            "Brand personality: {$personality}.",
            "Chosen layout structure: \"{$structure}\" — palettes must fit its character: {$character}",
        ];
        if ($brief->emotionalGoal !== '') {
            $lines[] = "It should make a visitor feel: {$brief->emotionalGoal}.";
        }
        if ($brief->colorAnchorsUse !== []) {
            $lines[] = 'HARMONIZE AROUND these existing brand colors (use as primary/secondary where it fits, build the rest to match): '.implode(', ', $brief->colorAnchorsUse).'.';
        }
        if ($brief->colorAnchorsAvoid !== []) {
            $lines[] = 'Avoid these colors: '.implode(', ', $brief->colorAnchorsAvoid).'.';
        }
        if ($brief->admiredBrand !== '') {
            $lines[] = "The client admires the feel of: {$brief->admiredBrand} (take inspiration, do not copy).";
        }
        if ($avoid !== []) {
            $lines[] = 'Make these DIFFERENT from prior sets: '.implode('; ', $avoid).'.';
        }

        $lines[] = '';
        $lines[] = 'Requirements for EACH candidate (WCAG-AA — these are enforced, design to pass):';
        $lines[] = '- A full 8-color palette in #RRGGBB. The text color MUST hit >= 4.5:1 on BOTH bg and bg_alt '
            .'(so keep bg/bg_alt light when text is dark, or dark when text is light); muted text >= 3:1 on bg.';
        $lines[] = '- The accent is the CTA color. Its button text is auto-chosen (white OR dark) for max contrast, '
            .'so a light, punchy accent is fine — just make the accent itself a saturated, deliberate color (not a '
            .'near-bg tint), distinct from the primary.';
        $lines[] = '- Use ONE of these vetted heading/body font pairings per candidate (spelled exactly; vary '
            .'the pairing across candidates): '.implode('; ', $pairLines).'.';
        $lines[] = '- An industry-grounded, SPECIFIC rationale (name the trade and what the colors do for it); '
            .'never generic filler like "evokes trust and professionalism".';
        $lines[] = '- Mark exactly ONE candidate "recommended": true (best industry-fit + personality-match + accessibility).';
        $lines[] = '';
        $lines[] = 'Respond with ONLY this JSON: {"candidates":[{'
            .'"tokens":{"--wf-color-primary":"#RRGGBB","--wf-color-secondary":"#RRGGBB","--wf-color-accent":"#RRGGBB",'
            .'"--wf-color-text":"#RRGGBB","--wf-color-text-muted":"#RRGGBB","--wf-color-bg":"#RRGGBB",'
            .'"--wf-color-bg-alt":"#RRGGBB","--wf-color-border":"#RRGGBB"},'
            .'"fonts":{"--wf-font-heading":"Family","--wf-font-body":"Family"},"rationale":"...","recommended":false}]}';

        return implode("\n", $lines);
    }

    private function system(): string
    {
        return 'You are a senior brand designer for local service businesses. You apply real color '
            .'theory and typographic pairing principles, and you choose with intent — the brand must '
            .'express the requested personality. You return STRICT JSON only, never prose or code fences.';
    }

    private function prompt(BrandBrief $brief): string
    {
        $personality = $brief->descriptor();

        $lines = [
            "Design a brand for a {$brief->industry} business.",
            "Brand personality: {$personality}.",
        ];

        if ($brief->emotionalGoal !== '') {
            $lines[] = "It should make a visitor feel: {$brief->emotionalGoal}.";
        }
        if ($brief->colorAnchorsUse !== []) {
            $lines[] = 'Prefer these colors if appropriate: '.implode(', ', $brief->colorAnchorsUse).'.';
        }
        if ($brief->colorAnchorsAvoid !== []) {
            $lines[] = 'Avoid these colors: '.implode(', ', $brief->colorAnchorsAvoid).'.';
        }
        if ($brief->admiredBrand !== '') {
            $lines[] = "The client admires the feel of: {$brief->admiredBrand} (take inspiration, do not copy).";
        }

        $lines[] = '';
        $lines[] = 'Requirements:';
        $lines[] = '- COLOR: a primary, an accent with clear contrast against the primary, and a dark '
            .'neutral text color that meets WCAG-AA (>= 4.5:1) on a light background. Colors must suit '
            .'the industry and personality. Use 6-digit hex (#RRGGBB).';
        $lines[] = '- TYPOGRAPHY: a professional heading + body pairing that follows real pairing '
            .'principles and expresses the personality. Use ONLY real, widely-available Google Fonts '
            .'families, spelled exactly as Google Fonts lists them (e.g. "Playfair Display", "Inter").';
        $lines[] = '- RATIONALE: 2-4 sentences explaining why these colors and fonts fit the industry '
            .'and personality (this is shown to the client).';
        $lines[] = '';
        $lines[] = 'Respond with ONLY this JSON: '
            .'{"palette":{"primary":"#RRGGBB","accent":"#RRGGBB","text":"#RRGGBB"},'
            .'"typography":{"heading":"Family Name","body":"Family Name"},"rationale":"..."}';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $palette
     * @param  list<string>  $adjustments  (by ref)
     * @return array{primary: string, accent: string, text: string}
     */
    private function validatePalette(array $palette, array &$adjustments): array
    {
        $safe = (array) config('launchpad.brand.safe_colors', []);

        $primary = $this->validateHex((string) ($palette['primary'] ?? ''))
            ?? $this->fallbackColor('primary', (string) ($safe['primary'] ?? '#0F62FE'), $adjustments);
        $accent = $this->validateHex((string) ($palette['accent'] ?? ''))
            ?? $this->fallbackColor('accent', (string) ($safe['accent'] ?? '#FF6F00'), $adjustments);
        $text = $this->validateHex((string) ($palette['text'] ?? ''))
            ?? $this->fallbackColor('text', (string) ($safe['text'] ?? '#1A1A1A'), $adjustments);

        // WCAG-AA guard: text must be readable on a light background, else the
        // cascade renders unreadable body copy. Correct to the safe neutral.
        $minContrast = (float) config('launchpad.brand.min_text_contrast', 4.5);
        if ($this->contrast($text, '#FFFFFF') < $minContrast) {
            $safeText = (string) ($safe['text'] ?? '#1A1A1A');
            $normalizedSafe = $this->validateHex($safeText) ?? '#1a1a1a';
            if ($text !== $normalizedSafe) {
                $adjustments[] = "Text color {$text} failed WCAG-AA on a light background — corrected to {$safeText}.";
                $text = $normalizedSafe;
            }
        }

        return ['primary' => $primary, 'accent' => $accent, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $typography
     * @param  list<string>  $adjustments  (by ref)
     * @return array{heading: string, body: string}
     */
    private function validateTypography(array $typography, array &$adjustments): array
    {
        return [
            'heading' => $this->validateFont('heading', (string) ($typography['heading'] ?? ''), $adjustments),
            'body' => $this->validateFont('body', (string) ($typography['body'] ?? ''), $adjustments),
        ];
    }

    /**
     * The font guard: resolve the family to its canonical Google Fonts spelling, or
     * fall back to the configured safe default and record why — so an invented or
     * misspelled family never reaches (and breaks) the Global Kit.
     *
     * @param  list<string>  $adjustments  (by ref)
     */
    private function validateFont(string $role, string $family, array &$adjustments): string
    {
        $canonical = $this->fonts->canonical($family);
        if ($canonical !== null) {
            return $canonical;
        }

        $safe = (string) config("launchpad.brand.safe_fonts.{$role}", $role === 'heading' ? 'Poppins' : 'Inter');
        $shown = trim($family) === '' ? '(none returned)' : "\"{$family}\"";
        $adjustments[] = "{$role} font {$shown} is not a loadable Google Font — fell back to {$safe}.";

        return $safe;
    }

    /**
     * @param  list<string>  $adjustments  (by ref)
     */
    private function fallbackColor(string $role, string $safe, array &$adjustments): string
    {
        $adjustments[] = "Invalid or missing {$role} color — fell back to {$safe}.";

        return $this->validateHex($safe) ?? '#1a1a1a';
    }

    /** Normalize a hex string to #RRGGBB, or null when it is not a valid hex color. */
    private function validateHex(string $hex): ?string
    {
        return ColorContrast::normalize($hex);
    }

    /** WCAG contrast ratio between two #RRGGBB colors (1–21). */
    private function contrast(string $a, string $b): float
    {
        return ColorContrast::ratio($a, $b);
    }

    /**
     * Tolerant JSON parse: take the outermost {...}, fence/prose tolerant.
     *
     * @return array<string, mixed>
     */
    private function parse(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
