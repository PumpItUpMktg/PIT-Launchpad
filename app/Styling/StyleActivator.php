<?php

namespace App\Styling;

use App\Branding\BrandColors;
use App\Branding\BrandVariationBuilder;
use App\Enums\VoiceStatus;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\VoiceProfile;
use App\Publishing\Chrome\SiteProfileAssembler;
use Throwable;

/**
 * The Gutenberg-pivot brand "push": resolve the site's block-theme style variation and activate it on
 * its WordPress (the theme.json global styles). Replaces the retired Elementor Global Kit push —
 * brand styling is one of the three theme.json variations, chosen by the recommend-with-override
 * model: the operator's explicit override wins; otherwise the voice recommendation; otherwise Clean
 * (the trustworthy default).
 */
final class StyleActivator
{
    public function __construct(
        private readonly WordpressClientFactory $factory,
        private readonly StyleRecommender $recommender,
        private readonly SiteProfileAssembler $profile,
        private readonly BrandVariationBuilder $brandVariation,
        private readonly VariationThemeJson $variationThemeJson,
    ) {}

    /** The logo-derived brand colors for a site, or null when no usable logo palette was extracted. */
    public function logoColors(Site $site): ?BrandColors
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->first();

        $set = is_array($branding?->logo_set) ? $branding->logo_set : [];
        $primary = trim((string) ($set['primary'] ?? ''));
        if ($primary === '') {
            return null;
        }

        $accent = trim((string) ($set['accent'] ?? ''));

        return new BrandColors($primary, $accent !== '' ? $accent : null);
    }

    /** Whether the logo-derived "Your brand colors" option is available for this site (a usable palette). */
    public function logoColorsAvailable(Site $site): bool
    {
        return $this->logoColors($site) !== null;
    }

    /**
     * The colors + heading font the site ACTUALLY renders in — the logo-derived variation when chosen,
     * else the resolved curated one. Lets the proof editor style a page exactly as it will ship, so the
     * operator reviews the real look, not the raw Account palette.
     *
     * @return array{primary: string, accent: string, heading_font: string}
     */
    public function activeLook(Site $site): array
    {
        $logo = $site->use_logo_colors ? $this->logoColors($site) : null;
        if ($logo !== null) {
            $resolved = $this->brandVariation->resolve($logo);
            $font = StyleVariation::from($resolved['base'])->tokens()['heading_font'];

            return ['primary' => $resolved['primary'], 'accent' => $resolved['accent'], 'heading_font' => $font];
        }

        $tokens = $this->resolve($site)->tokens();

        return ['primary' => $tokens['primary'], 'accent' => $tokens['accent'], 'heading_font' => $tokens['heading_font']];
    }

    /** The variation the site renders in: explicit override → voice recommendation → Clean default. */
    public function resolve(Site $site): StyleVariation
    {
        if ($site->style_variation instanceof StyleVariation) {
            return $site->style_variation;
        }

        return $this->recommended($site);
    }

    /**
     * The voice/AI recommendation for a site — the "AI pick" slot in the brand picker — independent of
     * any operator override. Voice recommendation when an active voice exists, else the Clean default.
     */
    public function recommended(Site $site): StyleVariation
    {
        $voice = VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', VoiceStatus::Active->value)
            ->first();

        if ($voice !== null) {
            return $this->recommender->recommend(StyleSignals::fromVoiceProfile($voice));
        }

        return StyleVariation::Clean;
    }

    /**
     * Activate the resolved variation on the site's WordPress. Returns the plugin's result plus the
     * variation applied; a connection/transport failure is caught and surfaced (not thrown).
     *
     * @return array<string, mixed>
     */
    public function activate(Site $site): array
    {
        $client = $this->factory->forSite($site);

        // The logo-derived "Your brand colors": build the per-tenant dynamic variation and push it
        // inline. Only taken when the operator chose it AND a usable logo palette exists.
        $logoColors = $site->use_logo_colors ? $this->logoColors($site) : null;
        if ($logoColors !== null) {
            try {
                $result = $client->activateStyleVariation(
                    BrandVariationBuilder::SLUG,
                    $this->brandVariation->build($logoColors),
                );
            } catch (WordpressException $e) {
                return ['updated' => false, 'error' => $e->getMessage(), 'variation' => BrandVariationBuilder::SLUG];
            }

            $this->pushChrome($client, $site);

            return ['variation' => BrandVariationBuilder::SLUG] + $result;
        }

        // Curated variation: explicit override → voice recommendation → Clean default. The full
        // theme.json variation (palette + typography) is sent INLINE — not as a bare slug the plugin
        // loads from the deployed theme's styles/{slug}.json. A stale deployed theme was the cause of
        // "I picked Forest but the site stays blue": the file the bare-slug push relied on carried no
        // palette, so WordPress fell back to the base theme.json colors. The inline doc, built from the
        // enum (the single source of truth that also generates those theme files), is authoritative and
        // paints the chosen palette regardless of the deployed theme's age.
        $variation = $this->resolve($site);

        try {
            $result = $client->activateStyleVariation($variation->value, $this->variationThemeJson->build($variation));
        } catch (WordpressException $e) {
            return ['updated' => false, 'error' => $e->getMessage(), 'variation' => $variation->value];
        }

        $this->pushChrome($client, $site);

        return ['variation' => $variation->value] + $result;
    }

    /**
     * The brand push also populates the universal header/footer chrome (brand + NAP + nav) — the same
     * setup step writes both the theme.json variation AND the site profile. Best-effort: a chrome-push
     * failure never fails the style activation.
     */
    private function pushChrome(WordpressClient $client, Site $site): void
    {
        try {
            $client->pushSiteProfile($this->profile->assemble($site));
        } catch (Throwable) {
            // Surfaced elsewhere (the operator can re-run launchpad:sync-site-profile); style stands.
        }
    }
}
