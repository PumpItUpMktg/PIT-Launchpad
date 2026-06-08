<?php

namespace App\ContentEngine\Drafting;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\RefreshTrigger;
use App\Models\Content;
use App\Models\RefreshEvent;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Str;

/**
 * The middle of the §6 pipeline: it takes a normalized DraftRequest, assembles
 * the split grounding, drafts with Sonnet, runs the verification pass, and emits
 * a `needs_review` draft for the §6c review queue. It ends at "draft ready for
 * review" — it does not build the review UI, publish, or render images.
 *
 * The refresh path re-drafts an existing row in place (bumping its version)
 * rather than creating a new Content.
 */
class DraftingEngine
{
    public function __construct(
        private readonly GroundingAssembler $assembler,
        private readonly Drafter $drafter,
        private readonly VerificationPass $verification,
    ) {}

    /**
     * @param  list<SourceRef>  $extraSources
     */
    public function run(DraftRequest $request, array $extraSources = []): DraftResult
    {
        $grounding = $this->assembler->assemble($request, $extraSources);
        $payload = $this->drafter->draft($request, $grounding);
        $verification = $this->verification->verify($payload, $grounding);

        $content = $request->isRefresh()
            ? $this->updateRefresh($request, $grounding, $payload, $verification)
            : $this->createDraft($request, $grounding, $payload, $verification);

        return new DraftResult($content, $payload, $verification, $request->isRefresh());
    }

    private function createDraft(
        DraftRequest $request,
        Grounding $grounding,
        DraftPayload $payload,
        VerificationResult $verification,
    ): Content {
        $title = $this->title($request, $payload);

        return Content::create([
            ...$this->draftAttributes($request, $grounding, $payload, $verification),
            // Lane provenance is fixed at creation and never rewritten by a
            // later refresh (the refresh cause lives on the RefreshEvent).
            'draft_trigger' => $request->trigger,
            'draft_lane' => $this->lane($request),
            'title' => $title,
            'slug' => $this->uniqueSlug($request->siteId, $payload->seo->slug !== '' ? $payload->seo->slug : $title),
            'version' => 1,
        ]);
    }

    /**
     * Re-draft an existing row in place: refresh its content, bump the version,
     * touch the denormalized refresh cache, and record exactly one RefreshEvent
     * (the source of truth) for the cause. The original lane (draft_trigger /
     * draft_lane) is deliberately left untouched, and refresh_of_content_id is
     * not written — it stays null for a possible future supersedes/version model.
     */
    private function updateRefresh(
        DraftRequest $request,
        Grounding $grounding,
        DraftPayload $payload,
        VerificationResult $verification,
    ): Content {
        $existing = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $request->siteId)
            ->findOrFail($request->refreshOfContentId);

        $existing->fill([
            ...$this->draftAttributes($request, $grounding, $payload, $verification),
            'title' => $this->title($request, $payload),
            'version' => (int) $existing->version + 1,
            'last_refreshed_at' => now(),
            'refresh_count' => (int) $existing->refresh_count + 1,
        ]);
        $existing->save();

        RefreshEvent::create([
            'site_id' => $existing->site_id,
            'content_id' => $existing->id,
            'trigger' => $request->refreshTrigger ?? RefreshTrigger::Manual,
        ]);

        return $existing;
    }

    /**
     * The re-drafted content fields shared by the create and refresh paths.
     * Excludes identity (title/slug), version, and lane provenance
     * (draft_trigger / draft_lane), which the two paths handle differently.
     *
     * @return array<string, mixed>
     */
    private function draftAttributes(
        DraftRequest $request,
        Grounding $grounding,
        DraftPayload $payload,
        VerificationResult $verification,
    ): array {
        $isPage = $request->kind === ContentKind::Page;

        return [
            'site_id' => $request->siteId,
            'silo_id' => $request->siloId,
            'kind' => $request->kind,
            'page_type' => $request->pageType,
            'intake_type' => $request->intakeType,
            'status' => ContentStatus::NeedsReview,
            'wireframe_kit_id' => $request->wireframeKitId,
            'target_keyword_id' => $request->targetKeywordId,
            'slot_payload' => $isPage ? $payload->slots : null,
            'body' => $isPage ? null : $payload->body,
            'voice_profile_version' => $grounding->voiceProfileVersion,
            'source_name' => $request->sourceName,
            'source_url' => $request->sourceUrl,
            'angle_hint' => $request->angleHint,
            'local_relevance' => $request->localRelevance,
            'meta' => [
                'seo' => $payload->seo->toArray(),
                'image_specs' => $payload->imageSpecsArray(),
                'towns' => $payload->towns,
                'sources_cited' => $verification->sourceAttributions,
            ],
            'verification' => $verification->toArray(),
        ];
    }

    private function title(DraftRequest $request, DraftPayload $payload): string
    {
        return $request->title
            ?? ($payload->seo->title !== '' ? $payload->seo->title : 'Untitled draft');
    }

    private function lane(DraftRequest $request): ?string
    {
        $brief = $request->brief;
        $lane = $brief['priority_lane'] ?? $brief['lane'] ?? null;

        if (is_string($lane) && $lane !== '') {
            return $lane;
        }

        return $request->trigger->isReactive() ? 'reactive' : null;
    }

    private function uniqueSlug(string $siteId, string $candidate): string
    {
        $base = Str::slug($candidate) ?: 'draft';
        $slug = $base;
        $suffix = 1;

        while (Content::withoutGlobalScope(SiteScope::class)
            ->withTrashed()
            ->where('site_id', $siteId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
