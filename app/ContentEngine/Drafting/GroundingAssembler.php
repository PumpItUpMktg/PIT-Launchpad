<?php

namespace App\ContentEngine\Drafting;

use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\VoiceProfile;
use App\Models\WireframeKit;
use App\PageBuilder\Schema\KitSchema;

/**
 * Builds the split grounding for a draft. It keeps the two pools structurally
 * apart: the Claims pool is drawn ONLY from substantiated Proof items, and the
 * Source pool from the request's originating news/competitor context. The active
 * VoiceProfile is loaded and its version captured for pinning, and the kit
 * schema is resolved for page drafts. Local towns are gated through the policy.
 *
 * All tenant reads bypass the site global scope and filter on the request's
 * site_id explicitly, so assembly is independent of the request-lifetime
 * CurrentSite singleton.
 */
class GroundingAssembler
{
    public function __construct(
        private readonly LocalInjectionPolicy $localInjection = new LocalInjectionPolicy,
    ) {}

    /**
     * @param  list<SourceRef>  $extraSources  competitor/news context beyond the request's own source
     */
    public function assemble(DraftRequest $request, array $extraSources = []): Grounding
    {
        $voice = $this->activeVoiceProfile($request->siteId);

        return new Grounding(
            claims: $this->claims($request->siteId),
            sources: $this->sources($request, $extraSources),
            voiceProfile: $this->voiceArray($voice),
            voiceProfileVersion: $voice !== null ? (int) $voice->version : 0,
            localInjectionAllowed: $request->allowsLocalInjection(),
            towns: $this->localInjection->townsFor($request),
            kit: $this->kit($request),
        );
    }

    /**
     * The Claims pool: substantiated Proof items only. An unsubstantiated proof
     * is never a fact the draft may assert.
     *
     * @return list<Claim>
     */
    private function claims(string $siteId): array
    {
        return ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('is_substantiated', true)
            ->get()
            ->map(fn (ProofItem $item) => Claim::fromProofItem($item))
            ->all();
    }

    /**
     * The Source pool: the request's originating source (reactive lane) plus any
     * injected competitor/news context. Never a source of business claims.
     *
     * @param  list<SourceRef>  $extraSources
     * @return list<SourceRef>
     */
    private function sources(DraftRequest $request, array $extraSources): array
    {
        $sources = [];

        if ($request->sourceName !== null && $request->sourceName !== '') {
            // Grounding follows the source. A direct-feed item with a captured
            // body grounds on the BODY; a Google News item (no source_url, hence
            // no body) grounds on METADATA only and is cited by name. The url is
            // always passed through — SourceRef decides citability (a Google News
            // redirect token collapses to name-only attribution).
            $hasBody = $request->sourceUrl !== null && $request->sourceUrl !== ''
                && $request->sourceBody !== null && $request->sourceBody !== '';

            $sources[] = new SourceRef(
                name: $request->sourceName,
                summary: $hasBody ? $request->sourceBody : $this->metadataSummary($request),
                url: $request->sourceUrl,
            );
        }

        return [...$sources, ...$extraSources];
    }

    /**
     * The metadata grounding for an item without a usable body: the title and the
     * advisory angle (the snippet/date the candidate carries), kept copyright-safe.
     */
    private function metadataSummary(DraftRequest $request): string
    {
        $parts = array_filter([$request->title, $request->angleHint], fn (?string $v) => $v !== null && $v !== '');

        return implode(' — ', $parts);
    }

    private function activeVoiceProfile(string $siteId): ?VoiceProfile
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
    private function voiceArray(?VoiceProfile $voice): array
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

    private function kit(DraftRequest $request): ?KitSchema
    {
        if ($request->wireframeKitId === null) {
            return null;
        }

        $kit = WireframeKit::find($request->wireframeKitId);

        return $kit?->schema();
    }
}
