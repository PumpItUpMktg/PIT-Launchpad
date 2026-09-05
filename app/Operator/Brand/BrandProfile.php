<?php

namespace App\Operator\Brand;

use App\Branding\BrandStudio;
use App\Branding\BrandVariationBuilder;
use App\Filament\Concerns\ManagesBrandKit;
use App\Filament\Pages\BrandBoard;
use App\Guided\StepGate;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Operator\ActiveTenant;
use App\Publishing\Chrome\SiteProfileAssembler;
use App\Publishing\ConnectionGate;
use App\Styling\StyleActivator;
use App\Styling\StyleVariation;

/**
 * The read-model behind the operator **Brand** workspace — one tenant's visual identity in one place:
 * the brand name + logo, the resolved look (primary / accent / heading font), and the full style-variation
 * picker (the logo-derived "brand colors" option, the AI/voice recommendation, then the curated
 * variations). UI-agnostic and testable; the Filament page ({@see BrandBoard}) is thin over it.
 *
 * This is the **block-theme** brand surface: styling is a `theme.json` style variation pushed through
 * {@see StyleActivator} (→ `/style`). It deliberately does NOT surface the legacy Elementor Global Kit
 * flow ({@see BrandStudio} → `/brand-kit`), which is quarantined per the Gutenberg-only
 * output contract — Elementor is not part of the system going forward.
 *
 * The style-option list mirrors the wizard's LOOK step ({@see ManagesBrandKit})
 * so the operator and the client see the exact same picker; the difference is only the tenant source —
 * here it is the locked {@see ActiveTenant}, never a per-page picker.
 */
class BrandProfile
{
    public function __construct(
        private readonly StyleActivator $activator,
        private readonly BrandVariationBuilder $brandVariation,
        private readonly ConnectionGate $connections,
        private readonly StepGate $steps,
    ) {}

    /**
     * @return array{
     *     brand_name: string, logo_url: ?string, has_logo: bool, header_tone: string,
     *     look: array{primary: string, accent: string, heading_font: string},
     *     active_label: string, uses_logo: bool, shadows_curated: bool, curated_label: ?string,
     *     options: list<array{key: string, label: string, blurb: string, swatches: list<string>, recommended: bool, chosen: bool, dark: bool, badge: ?string}>,
     *     pushed: bool, has_wp: bool
     * }
     */
    public function for(?string $siteId): ?array
    {
        if ($siteId === null) {
            return null;
        }

        $site = Site::query()->withoutGlobalScope(VisibleSiteScope::class)->find($siteId);
        if ($site === null) {
            return null;
        }

        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->first();
        $logoSet = is_array($branding?->logo_set) ? $branding->logo_set : [];
        $logoUrl = isset($logoSet['url']) && is_string($logoSet['url']) && $logoSet['url'] !== '' ? $logoSet['url'] : null;

        $usesLogo = (bool) $site->use_logo_colors && $this->activator->logoColorsAvailable($site);
        $curated = $site->style_variation instanceof StyleVariation ? $site->style_variation : null;

        return [
            'brand_name' => (string) $site->brand_name,
            'logo_url' => $logoUrl,
            'has_logo' => $logoUrl !== null,
            'header_tone' => $this->headerTone($site, $logoSet),
            'look' => $this->activator->activeLook($site),
            'active_label' => $usesLogo ? 'Your brand colors (from your logo)' : $this->activator->resolve($site)->label(),
            'uses_logo' => $usesLogo,
            'shadows_curated' => $usesLogo && $curated !== null,
            'curated_label' => $curated?->label(),
            'options' => $this->options($site),
            'pushed' => $this->steps->state($site)->brand_pushed,
            'has_wp' => $this->connections->hasVerifiedWordpress($siteId),
        ];
    }

    /** Operator override → the logo's own header tone → light. Mirrors {@see SiteProfileAssembler}. */
    private function headerTone(Site $site, array $logoSet): string
    {
        if (is_string($site->header_tone_override) && $site->header_tone_override !== '') {
            return $site->header_tone_override;
        }

        return isset($logoSet['header_tone']) && is_string($logoSet['header_tone']) && $logoSet['header_tone'] !== ''
            ? $logoSet['header_tone']
            : 'light';
    }

    /**
     * The style-picker options, in choose order: the logo-derived palette first (when the logo yields
     * one), then the AI/voice recommendation, then the remaining curated variations in declaration order.
     * Each option carries its six-role swatches so the picker previews the whole look, not two colors.
     * Ported from {@see ManagesBrandKit::getStyleOptionsProperty()} against a
     * passed Site rather than a wizard-resolved one.
     *
     * @return list<array{key: string, label: string, blurb: string, swatches: list<string>, recommended: bool, chosen: bool, dark: bool, badge: ?string}>
     */
    private function options(Site $site): array
    {
        $recommended = $this->activator->recommended($site);
        $chosen = $site->style_variation;
        $usesLogo = (bool) $site->use_logo_colors;

        $options = [];

        $logoColors = $this->activator->logoColors($site);
        if ($logoColors !== null) {
            $built = $this->brandVariation->build($logoColors);
            $pal = [];
            foreach ($built['settings']['color']['palette'] as $c) {
                $pal[$c['slug']] = $c['color'];
            }
            $options[] = [
                'key' => 'brand_colors',
                'label' => 'Your brand colors',
                'blurb' => 'Pulled straight from your logo — your exact colors on a complete, coherent palette.',
                'swatches' => [$pal['base'], $pal['surface'], $pal['contrast'], $pal['primary'], $pal['accent'], $pal['button']],
                'recommended' => false,
                'chosen' => $usesLogo,
                'dark' => false,
                'badge' => 'From your logo',
            ];
        }

        $ordered = array_merge(
            [$recommended],
            array_values(array_filter(StyleVariation::cases(), fn (StyleVariation $v): bool => $v !== $recommended)),
        );
        foreach ($ordered as $variation) {
            $p = $variation->palette();
            $options[] = [
                'key' => $variation->value,
                'label' => $variation->label(),
                'blurb' => $variation->blurb(),
                'swatches' => [$p['base'], $p['surface'], $p['text'], $p['primary'], $p['highlight'], $p['button']],
                'recommended' => $variation === $recommended,
                'chosen' => ! $usesLogo && $chosen === $variation,
                'dark' => $variation->isDark(),
                'badge' => $variation === $recommended ? 'AI pick' : null,
            ];
        }

        return $options;
    }
}
