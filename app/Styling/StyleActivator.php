<?php

namespace App\Styling;

use App\Enums\VoiceStatus;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\VoiceProfile;

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
    ) {}

    /** The variation the site renders in: explicit override → voice recommendation → Clean default. */
    public function resolve(Site $site): StyleVariation
    {
        if ($site->style_variation instanceof StyleVariation) {
            return $site->style_variation;
        }

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
        $variation = $this->resolve($site);

        try {
            $result = $this->factory->forSite($site)->activateStyle($variation->value);
        } catch (WordpressException $e) {
            return ['updated' => false, 'error' => $e->getMessage(), 'variation' => $variation->value];
        }

        return ['variation' => $variation->value] + $result;
    }
}
