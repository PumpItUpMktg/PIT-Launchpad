<?php

namespace App\Interview;

use App\Enums\VoiceStatus;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\VoiceProfile;
use Illuminate\Support\Facades\DB;

/**
 * Persists an owner-interview extraction into §1 entities for a Site: the SiloSeed
 * snapshot onto a (single, idempotent) SiloBlueprint, and the voice payload as a new
 * versioned, activated VoiceProfile. Voice versioning + the one-active-per-site
 * activation mirror IntakeCollector exactly (index-safe: created Draft, then prior
 * actives archived before this one flips Active). The whole write is one transaction.
 */
final class InterviewPersister
{
    /**
     * @param  list<array{role: string, text: string}>  $transcript  the raw conversation, kept for re-extraction
     */
    public function persist(Site $site, ExtractionResult $result, array $transcript = []): PersistResult
    {
        return DB::transaction(function () use ($site, $result, $transcript): PersistResult {
            return new PersistResult(
                $this->persistSeed($site, $result->seed, $transcript),
                $this->persistVoice($site, $result->voice),
            );
        });
    }

    /**
     * @param  list<array{role: string, text: string}>  $transcript
     */
    private function persistSeed(Site $site, SiloSeed $seed, array $transcript): SiloBlueprint
    {
        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->firstOrNew(['site_id' => $site->id]);

        $blueprint->fill([
            'trade' => $seed->trade,
            'seed' => $seed->toArray(),
            'transcript' => $transcript === [] ? $blueprint->transcript : $transcript,
        ])->save();

        return $blueprint;
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function persistVoice(Site $site, array $voice): VoiceProfile
    {
        $version = (int) VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->max('version');

        $profile = VoiceProfile::create([
            'site_id' => $site->id,
            'version' => $version + 1,
            'status' => VoiceStatus::Draft,
            'framing_model' => $voice['framing_model'] ?? 'problem_solution',
            'tone_axes' => $voice['tone_axes'] ?? null,
            'reading_level' => $voice['reading_level'] ?? null,
            'persona' => $voice['persona'] ?? null,
            'language_rules' => $voice['language_rules'] ?? null,
            'audience' => $voice['audience'] ?? null,
            'cta_voice' => $voice['cta_voice'] ?? null,
        ]);

        // Activate it (one active per site): archive any prior active, then flip.
        VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', VoiceStatus::Active->value)
            ->whereKeyNot($profile->id)
            ->update(['status' => VoiceStatus::Archived->value]);

        $profile->update(['status' => VoiceStatus::Active]);

        return $profile;
    }
}
