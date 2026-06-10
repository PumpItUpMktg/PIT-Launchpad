<?php

namespace App\ContentEngine\Drafting;

use App\Models\Scopes\SiteScope;
use App\Models\VoiceProfile;

/**
 * Resolves a site's active VoiceProfile and flattens it for a prompt — identical
 * for every draft kind, so it lives in the shared core (both GroundingAssembler
 * and PageGroundingAssembler use it rather than each re-querying). Bypasses the
 * site global scope and filters on site_id explicitly (queue/console safe).
 */
final class VoiceResolver
{
    public function active(string $siteId): ?VoiceProfile
    {
        return VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?VoiceProfile $voice): array
    {
        if ($voice === null) {
            return [];
        }

        return [
            'version' => $voice->version,
            'framing_model' => $voice->framing_model,
            'tone_axes' => $voice->tone_axes,
            'reading_level' => $voice->reading_level,
            'jargon_policy' => $voice->jargon_policy,
            'format_conventions' => $voice->format_conventions,
            'language_rules' => $voice->language_rules,
            'audience' => $voice->audience,
            'persona' => $voice->persona,
            'cta_voice' => $voice->cta_voice,
        ];
    }
}
