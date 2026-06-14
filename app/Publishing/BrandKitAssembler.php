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
     * @return array{colors: array<string, string>, fonts: array<string, array<string, string>>}|null
     */
    public function forSite(string $siteId): ?array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->first();

        if ($branding === null) {
            return null;
        }

        $colors = $this->colors(is_array($branding->palette) ? $branding->palette : []);
        $fonts = $this->fonts(is_array($branding->typography) ? $branding->typography : []);

        if ($colors === [] && $fonts === []) {
            return null;
        }

        return ['colors' => $colors, 'fonts' => $fonts];
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
