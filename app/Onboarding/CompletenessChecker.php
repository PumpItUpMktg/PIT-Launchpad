<?php

namespace App\Onboarding;

use App\Enums\MarketTier;
use App\Enums\VoiceStatus;
use App\Models\ConversionConfig;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\VoiceProfile;

/**
 * The launch completeness gate: the intake must be sufficient to fill kit slots
 * and carry the refresh anchors. Surfaces exactly what is missing.
 */
class CompletenessChecker
{
    /**
     * @return list<string>
     */
    public function missing(Site $site): array
    {
        $missing = [];

        if (! SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists()) {
            $missing[] = 'branding';
        }

        if (! Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists()) {
            $missing[] = 'service';
        }

        if (! Market::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('tier', MarketTier::Priority->value)->exists()) {
            $missing[] = 'priority_market';
        }

        if (! ProofItem::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_substantiated', true)->exists()) {
            $missing[] = 'substantiated_proof';
        }

        $conversion = ConversionConfig::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($conversion === null || ($conversion->primary_actions ?? []) === []) {
            $missing[] = 'conversion_config';
        }

        if (! VoiceProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('status', VoiceStatus::Active->value)->exists()) {
            $missing[] = 'active_voice';
        }

        if (! Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists()) {
            $missing[] = 'keyword_anchor';
        }

        return $missing;
    }

    public function isComplete(Site $site): bool
    {
        return $this->missing($site) === [];
    }
}
