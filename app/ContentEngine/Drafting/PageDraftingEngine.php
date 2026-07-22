<?php

namespace App\ContentEngine\Drafting;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SlotContentType;
use App\Enums\SlotRole;
use App\Models\Content;
use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Validation\KitValidator;
use App\PageBuilder\Validation\ValidationCode;
use App\PageBuilder\Validation\ValidationContext;
use App\PageBuilder\Validation\ValidationFailure;
use App\PageBuilder\Validation\ValidationResult;
use Illuminate\Support\Facades\Log;

/**
 * The PAGE middle of the §6 pipeline (sibling of the post DraftingEngine): it
 * assembles page grounding, drafts a kit-keyed slot map via PageDrafter, and —
 * the page-specific acceptance test — validates the slots against the kit schema
 * before persisting. Unknown keys are dropped (an off-schema key renders as a
 * blank section); a structurally invalid draft (missing required slot, bad
 * cardinality/content-type/length) is surfaced as a draft failure through the
 * shared DraftGuard, with no status flip. Media presence + entity grounding stay
 * publish-time gates (PublishEligibility), not draft-time.
 *
 * It re-drafts the existing seeded page IN PLACE (slot_payload), like the post
 * candidate path — never spawns a new Content.
 */
class PageDraftingEngine
{
    /** The structural codes that block a draft (vs media/entity, which gate publish). */
    private const STRUCTURAL_CODES = [
        ValidationCode::MissingRequiredSlot,
        ValidationCode::EmptyRequiredSlot,
        ValidationCode::LengthBelowMinimum,
        ValidationCode::LengthAboveMaximum,
        ValidationCode::CardinalityBelowMinimum,
        ValidationCode::CardinalityAboveMaximum,
        ValidationCode::ContentTypeMismatch,
    ];

    public function __construct(
        private readonly PageGroundingAssembler $assembler,
        private readonly PageDrafter $drafter,
        private readonly DraftGuard $guard,
        private readonly KitValidator $validator,
        private readonly SlotShaper $shaper,
    ) {}

    public function draftPage(Content $page): Content
    {
        $grounding = null;

        // Grounding assembly + the model call run inside the guard, so a kit-less
        // page (assembler throws) or an empty/failed draft surfaces identically.
        $attempt = $this->guard->run(
            ContentKind::Page,
            $page,
            $page->id,
            (string) $page->site_id,
            function () use ($page, &$grounding): DraftAttempt {
                $grounding = $this->assembler->assemble($page);

                return $this->drafter->attempt($grounding);
            },
        );

        /** @var PageGrounding $grounding */
        $kit = $grounding->kit;

        // Shape the raw sentinel slots to the kit's content types and drop
        // off-schema keys (the render contract is the slot key), then validate
        // structure. A structural failure is surfaced; media/entity gate publish.
        $slots = $this->shaper->shape($kit->slots, $attempt->payload->slots ?? []);

        // Degrade by omission, never by invention: an intake-bound slot whose intake wasn't captured
        // is dropped from the draft, not kept as fabricated copy. Model proposes, deterministic check
        // enforces — the same discipline as fonts and internal links. The flags also let a
        // conditioned slot count as required when its intake IS present.
        $flags = $this->intakeFlags($grounding);
        $slots = $this->dropConditionedOut($kit, $slots, $flags);

        // Repair over-length text before validating: an LLM draft that overshoots a char cap by a
        // little (or a lot) is clamped to the kit max at a sentence/word boundary, not hard-rejected.
        // Only over-max scalar text is touched; in-bounds slots pass through unchanged.
        $slots = $this->clampTextLengths($kit, $slots);

        $structural = $this->structuralFailures(
            $this->validator->validate($kit, $slots, new ValidationContext($page, flags: $flags)),
        );

        if ($structural !== []) {
            $this->guard->fail(
                $page,
                $page->id,
                (string) $page->site_id,
                ContentKind::Page,
                DraftFailure::schemaRejected(array_map(fn (ValidationFailure $f) => $f->message, $structural)),
                null,
            );
        }

        // Section headings are drafted slots; a section that came back empty renders the static label.
        // That is the ERROR fallback — flag it in the generation log so it's never a silent default.
        $headingFallbacks = $this->headingFallbacks($kit, $slots);
        if ($headingFallbacks !== []) {
            Log::warning('Page draft fell back to static section headings', [
                'content_id' => $page->id,
                'site_id' => (string) $page->site_id,
                'slots' => $headingFallbacks,
            ]);
        }

        $this->persist($page, $grounding, $attempt->payload, $slots, $headingFallbacks);

        return $page;
    }

    /**
     * The section-heading slots (content_type=heading, role=body_explainer) the drafter left empty, so
     * the composer will render the static label instead of a drafted, service-specific H2. The label is
     * the error fallback, never the intended path — this list is logged and stamped into meta.
     *
     * @param  array<string, mixed>  $slots
     * @return list<string>
     */
    private function headingFallbacks(KitSchema $kit, array $slots): array
    {
        $fallbacks = [];
        foreach ($kit->slots as $slot) {
            if ($slot->contentType !== SlotContentType::Heading || $slot->role !== SlotRole::BodyExplainer) {
                continue;
            }
            $value = $slots[$slot->key] ?? null;
            $text = trim(is_array($value) ? (string) ($value[0] ?? '') : (string) ($value ?? ''));
            if ($text === '') {
                $fallbacks[] = $slot->key;
            }
        }

        return $fallbacks;
    }

    /**
     * Presence flags for the captured narrative fields (has_intake_story, …) — kit slots condition on
     * these so a slot is required when its intake is present and absent (dropped) when it isn't.
     *
     * @return array<string, bool>
     */
    private function intakeFlags(PageGrounding $grounding): array
    {
        $flags = [];
        foreach (array_keys($grounding->narrative) as $field) {
            $flags["has_intake_{$field}"] = true;
        }

        return $flags;
    }

    /**
     * Drop slots whose kit condition isn't met by the context flags — the deterministic enforcement
     * of degrade-by-omission (a fabricated intake-bound slot can't survive into the payload).
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, bool>  $flags
     * @return array<string, mixed>
     */
    private function dropConditionedOut(KitSchema $kit, array $slots, array $flags): array
    {
        foreach (array_keys($slots) as $key) {
            $slot = $kit->slot((string) $key);

            // Scope strictly to intake conditions (has_intake_*). Other conditions (has_proof,
            // has_location, …) are publish-time gates whose flags aren't computed at draft — touching
            // them here would change service-page behavior. Only intake degrades by omission here.
            if ($slot?->condition !== null
                && str_starts_with($slot->condition->field, 'has_intake_')
                && ! $slot->appliesTo($flags)) {
                unset($slots[$key]);
            }
        }

        return $slots;
    }

    /**
     * Clamp each scalar text slot to its kit `max_length` (repeaters + non-text slots untouched).
     * The deterministic acceptance repair for over-length copy — {@see SlotLengthClamp}.
     *
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function clampTextLengths(KitSchema $kit, array $slots): array
    {
        foreach ($slots as $key => $value) {
            $slot = $kit->slot((string) $key);
            if ($slot === null || $slot->isRepeater() || ! $slot->contentType->isText()) {
                continue;
            }
            $max = $slot->constraints->maxLength;
            if ($max === null || ! is_string($value)) {
                continue;
            }
            $slots[$key] = SlotLengthClamp::clamp($value, $max);
        }

        return $slots;
    }

    /**
     * @return list<ValidationFailure>
     */
    private function structuralFailures(ValidationResult $result): array
    {
        return array_values(array_filter(
            $result->failures,
            fn (ValidationFailure $f) => in_array($f->code, self::STRUCTURAL_CODES, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  list<string>  $headingFallbacks  section-heading slots that fell back to the static label
     */
    private function persist(Content $page, PageGrounding $grounding, DraftPayload $payload, array $slots, array $headingFallbacks = []): void
    {
        $meta = [
            'seo' => $payload->seo->toArray(),
            'image_specs' => $payload->imageSpecsArray(),
        ];
        // The generation-log flag: which section H2s rendered a static label because the drafter left
        // the slot empty. Present only when there was a fallback, so its absence means every H2 drafted.
        if ($headingFallbacks !== []) {
            $meta['heading_fallbacks'] = $headingFallbacks;
        }

        $page->fill([
            'status' => ContentStatus::NeedsReview,
            'slot_payload' => $slots,
            'body' => null,
            'voice_profile_version' => $grounding->voiceProfileVersion,
            'wireframe_kit_version' => $grounding->kit->version,
            'meta' => $meta,
        ])->save();
    }
}
