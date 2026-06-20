<?php

namespace App\Publishing;

use App\Models\Scopes\SiteScope;
use App\Models\SiteBranding;

/**
 * Assembles the brand-kit push payload from a Site's §1 intake branding — the
 * engine half of C5 (brand intake → Elementor Global Kit). It maps the intake
 * `palette` / `typography` to the Elementor system slots (primary / secondary /
 * text / accent) the companion plugin writes into the active Global Kit, which is
 * exactly what generated/bound templates reference via `__globals__`.
 *
 * It is intentionally tolerant and forward-compatible: today intake captures only
 * a primary color (the wizard's single colour field), so the payload carries just
 * that; as the Identity step grows to capture accent/text colors and heading/body
 * fonts, those slots light up automatically with no change here. Returns null when
 * the tenant has captured nothing to push (the caller skips the push, honestly).
 */
class BrandKitAssembler
{
    /**
     * Intake typography keys → Elementor system typography slots. The intake names
     * follow design roles (heading/body); Elementor's slots are primary/text. We
     * also accept the native slot ids directly.
     */
    private const FONT_SLOTS = [
        'heading' => 'primary',
        'body' => 'text',
        'primary' => 'primary',
        'secondary' => 'secondary',
        'text' => 'text',
        'accent' => 'accent',
    ];

    private const COLOR_SLOTS = ['primary', 'secondary', 'text', 'accent'];

    /**
     * Intake palette keys → named Elementor CUSTOM globals with STABLE `_id`s. Elementor only
     * has four SYSTEM color slots (primary/secondary/text/accent); the rest of the brand
     * (text_muted, bg, bg_alt, border, on_accent) is written as custom globals so every color
     * lands in the Global Kit and resolves via `var(--e-global-color-{id})` — which is exactly
     * what the baseline launchpad.css references. The ids are stable so a regenerate refreshes
     * the same globals (the plugin replaces them cleanly), never stale duplicates.
     */
    private const CUSTOM_COLOR_SLOTS = [
        'text_muted' => ['id' => 'lptextmuted', 'title' => 'Text Muted'],
        'text-muted' => ['id' => 'lptextmuted', 'title' => 'Text Muted'],
        'bg' => ['id' => 'lpbg', 'title' => 'Background'],
        'bg_alt' => ['id' => 'lpbgalt', 'title' => 'Background Alt'],
        'bg-alt' => ['id' => 'lpbgalt', 'title' => 'Background Alt'],
        'border' => ['id' => 'lpborder', 'title' => 'Border'],
        'on_accent' => ['id' => 'lponaccent', 'title' => 'On Accent'],
    ];

    /**
     * Intake palette keys → the brand `--wf-color-*` custom properties the base wf-*
     * stylesheet consumes on native pages. Accepts both `text_muted`/`bg_alt` and
     * the hyphenated aliases. Any key absent simply falls back to the stylesheet
     * default (degrade by omission).
     */
    private const WF_COLOR_TOKENS = [
        'primary' => '--wf-color-primary',
        'secondary' => '--wf-color-secondary',
        'accent' => '--wf-color-accent',
        'on_accent' => '--wf-color-on-accent',
        'text' => '--wf-color-text',
        'text_muted' => '--wf-color-text-muted',
        'text-muted' => '--wf-color-text-muted',
        'bg' => '--wf-color-bg',
        'bg_alt' => '--wf-color-bg-alt',
        'bg-alt' => '--wf-color-bg-alt',
        'border' => '--wf-color-border',
    ];

    private const VALID_STRUCTURES = ['trust', 'bold', 'warm'];

    /**
     * @return array{colors: array<string, string>, custom_colors: list<array{_id: string, title: string, color: string}>, fonts: array<string, array<string, string>>, wf_tokens: array<string, string>, structure: string}|null
     */
    public function forSite(string $siteId): ?array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->first();

        if ($branding === null) {
            return null;
        }

        $palette = is_array($branding->palette) ? $branding->palette : [];
        $typography = is_array($branding->typography) ? $branding->typography : [];

        $colors = $this->colors($palette);
        $fonts = $this->fonts($typography);

        if ($colors === [] && $fonts === []) {
            return null;
        }

        return [
            'colors' => $colors,                          // Elementor Global Kit SYSTEM slots
            'custom_colors' => $this->customColors($palette), // named CUSTOM globals (stable ids)
            'fonts' => $fonts,
            'wf_tokens' => $this->wfTokens($palette, $typography), // --wf-* for native pages
            'structure' => $this->structure($branding->structure_preset),
        ];
    }

    /**
     * The extended palette tokens as named Elementor custom globals (`{_id, title, color}`),
     * stable-id'd so the kit write replaces them cleanly on every regenerate.
     *
     * @param  array<string, mixed>  $palette
     * @return list<array{_id: string, title: string, color: string}>
     */
    private function customColors(array $palette): array
    {
        $out = [];
        $seen = [];
        foreach (self::CUSTOM_COLOR_SLOTS as $key => $slot) {
            $value = $palette[$key] ?? null;
            if (is_string($value) && trim($value) !== '' && ! isset($seen[$slot['id']])) {
                $seen[$slot['id']] = true;
                $out[] = ['_id' => $slot['id'], 'title' => $slot['title'], 'color' => trim($value)];
            }
        }

        return $out;
    }

    /**
     * The brand `--wf-*` token map for native pages: palette → `--wf-color-*`,
     * typography heading/body → `--wf-font-*`. Only present values are emitted.
     *
     * @param  array<string, mixed>  $palette
     * @param  array<string, mixed>  $typography
     * @return array<string, string>
     */
    private function wfTokens(array $palette, array $typography): array
    {
        $tokens = [];
        foreach (self::WF_COLOR_TOKENS as $key => $token) {
            $value = $palette[$key] ?? null;
            if (is_string($value) && trim($value) !== '' && ! isset($tokens[$token])) {
                $tokens[$token] = trim($value);
            }
        }

        foreach (['heading' => '--wf-font-heading', 'body' => '--wf-font-body'] as $key => $token) {
            $font = $this->normalizeFont($typography[$key] ?? null);
            if ($font !== null) {
                $tokens[$token] = $font['family'];
            }
        }

        return $tokens;
    }

    private function structure(mixed $preset): string
    {
        return is_string($preset) && in_array($preset, self::VALID_STRUCTURES, true) ? $preset : 'trust';
    }

    /**
     * Map the intake palette to system color slots, keeping only present, string
     * values for the four known slots.
     *
     * @param  array<string, mixed>  $palette
     * @return array<string, string>
     */
    private function colors(array $palette): array
    {
        $colors = [];
        foreach (self::COLOR_SLOTS as $slot) {
            $value = $palette[$slot] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $colors[$slot] = trim($value);
            }
        }

        return $colors;
    }

    /**
     * Map the intake typography to system typography slots. A value may be a bare
     * family string ("Inter") or a `{family, weight?}` shape; both normalize to
     * `{family, weight?}`. A later intake key wins over an earlier alias for the
     * same slot only when it carries a family.
     *
     * @param  array<string, mixed>  $typography
     * @return array<string, array<string, string>>
     */
    private function fonts(array $typography): array
    {
        $fonts = [];
        foreach ($typography as $key => $value) {
            $slot = self::FONT_SLOTS[$key] ?? null;
            if ($slot === null || isset($fonts[$slot])) {
                continue;
            }

            $font = $this->normalizeFont($value);
            if ($font !== null) {
                $fonts[$slot] = $font;
            }
        }

        return $fonts;
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeFont(mixed $value): ?array
    {
        if (is_string($value) && trim($value) !== '') {
            return ['family' => trim($value)];
        }

        if (is_array($value)) {
            $family = (string) ($value['family'] ?? $value['font_family'] ?? '');
            if (trim($family) === '') {
                return null;
            }

            $font = ['family' => trim($family)];
            $weight = $value['weight'] ?? $value['font_weight'] ?? null;
            if (is_string($weight) || is_int($weight)) {
                $weight = trim((string) $weight);
                if ($weight !== '') {
                    $font['weight'] = $weight;
                }
            }

            return $font;
        }

        return null;
    }
}
