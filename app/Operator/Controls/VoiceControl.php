<?php

namespace App\Operator\Controls;

use App\Enums\VoiceStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\VoiceProfile;
use Illuminate\Support\Collection;

/**
 * VoiceProfile control: view the versioned profiles, see the active version and
 * which version is pinned to recent content, and activate a version (a new
 * version comes from a §7a/VoiceKit re-interview). One active profile per site —
 * activating archives the prior active.
 */
class VoiceControl
{
    /**
     * @return Collection<int, VoiceProfile>
     */
    public function versions(Site $site): Collection
    {
        return VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByDesc('version')
            ->get();
    }

    public function activeVersion(Site $site): ?int
    {
        $active = VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', VoiceStatus::Active->value)
            ->first();

        return $active !== null ? (int) $active->version : null;
    }

    /**
     * The voice_profile_version distribution across the site's recent content —
     * which voice version is pinned where.
     *
     * @return array<int, int> version => content count
     */
    public function pinnedVersions(Site $site, int $limit = 50): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('voice_profile_version')
            ->latest()
            ->limit($limit)
            ->pluck('voice_profile_version')
            ->countBy()
            ->mapWithKeys(fn (int $count, int|string $version) => [(int) $version => $count])
            ->all();
    }

    /**
     * Activate a profile version — archives the prior active (one active per site).
     */
    public function activate(VoiceProfile $profile): VoiceProfile
    {
        VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $profile->site_id)
            ->where('status', VoiceStatus::Active->value)
            ->whereKeyNot($profile->getKey())
            ->update(['status' => VoiceStatus::Archived->value]);

        $profile->forceFill(['status' => VoiceStatus::Active])->save();

        return $profile;
    }
}
