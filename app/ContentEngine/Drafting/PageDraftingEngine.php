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
    /**
     * The structural codes that block a draft (vs media/entity, which gate publish). A budget OVERSHOOT
     * ({@see ValidationCode::LengthAboveMaximum}) is deliberately NOT here: it degrades (retry → truncate
     * → publish) rather than failing the page — a slot-budget problem may never leave a page in `failed`.
     */
    private const STRUCTURAL_CODES = [
        ValidationCode::MissingRequiredSlot,
        ValidationCode::EmptyRequiredSlot,
        ValidationCode::LengthBelowMinimum,
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
        $flags = $this->intakeFlags($grounding);

        // Shape the raw sentinel slots to the kit's content types and drop off-schema keys (the render
        // contract is the slot key), then degrade-by-omission the intake-bound slots.
        $payload = $attempt->payload;
        $slots = $this->dropConditionedOut($kit, $this->shaper->shape($kit->slots, $payload->slots ?? []), $flags);

        // BUDGET DEGRADE (report fix 1) — a char-cap overshoot must not fail the page. First, ONE bounded
        // retry: if a slot ran past its hard max, re-prompt the drafter naming the overshoot with a
        // ~10%-tighter target and adopt the retry if it produced content. Then TRUNCATE any residual
        // overshoot at a sentence boundary (SlotLengthClamp) and publish — never reject.
        $overshoot = $this->lengthOvershoots($this->validator->validate($kit, $slots, new ValidationContext($page, flags: $flags)));
        if ($overshoot !== []) {
            $retry = $this->drafter->attempt($grounding, $this->correctiveNote($kit, $slots, $overshoot));
            if (($retry->payload->slots ?? []) !== []) {
                $payload = $retry->payload;
                $slots = $this->dropConditionedOut($kit, $this->shaper->shape($kit->slots, $payload->slots ?? []), $flags);
            }
        }

        // Final truncate: clamp every over-length text slot (scalar + repeater items) to its kit max at a
        // sentence/word boundary. A truncated slot joins the fallback-label family in the generation log.
        $truncated = [];
        $slots = $this->clampTextLengths($kit, $slots, $truncated);
        if ($truncated !== []) {
            Log::warning('slot_truncated', [
                'content_id' => $page->id,
                'site_id' => (string) $page->site_id,
                'slots' => $truncated,
            ]);
        }

        // Validate structure. LengthAboveMaximum is NOT structural (truncated above), so a budget
        // overshoot never reaches here as a failure; a genuinely broken draft still surfaces.
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

        $this->persist($page, $grounding, $payload, $slots, $headingFallbacks, $truncated);

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
     * The slot keys whose drafted value ran past its kit `max_length` — the budget overshoots that
     * trigger the one bounded retry.
     *
     * @return list<string>
     */
    private function lengthOvershoots(ValidationResult $result): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (ValidationFailure $f): ?string => $f->code === ValidationCode::LengthAboveMaximum ? $f->slot : null,
            $result->failures,
        ))));
    }

    /**
     * The retry correction: name each overshoot slot with its hard max and a ~10%-tighter target so the
     * re-draft comes in under budget (the ~10% is the safety margin the spec calls for).
     *
     * @param  array<string, mixed>  $slots
     * @param  list<string>  $overshoot
     */
    private function correctiveNote(KitSchema $kit, array $slots, array $overshoot): string
    {
        $lines = [];
        foreach ($overshoot as $key) {
            $slot = $kit->slot($key);
            $max = $slot?->constraints->maxLength;
            if ($max === null) {
                continue;
            }
            $was = mb_strlen(trim(is_array($slots[$key] ?? null) ? (string) ($slots[$key][0] ?? '') : (string) ($slots[$key] ?? '')));
            $target = (int) floor($max * 0.9);
            $lines[] = "- {$key}: your draft was {$was} chars; the hard max is {$max}. Rewrite it to UNDER {$target} chars — keep the meaning, cut words.";
        }

        if ($lines === []) {
            return '';
        }

        return 'CORRECTION — your previous draft OVERSHOT these character budgets and was rejected. This '
            ."attempt MUST come in under the tightened target for each, keeping every other slot valid:\n"
            .implode("\n", $lines);
    }

    /**
     * Truncate every over-length text slot to its kit `max_length` at a sentence/word boundary
     * ({@see SlotLengthClamp}) — scalar slots AND the string items of a repeater (e.g. a list). Records
     * the truncated slot keys in $truncated (by ref) for the generation log. Non-text / in-bounds slots
     * pass through unchanged.
     *
     * @param  array<string, mixed>  $slots
     * @param  list<string>  $truncated
     * @return array<string, mixed>
     */
    private function clampTextLengths(KitSchema $kit, array $slots, array &$truncated): array
    {
        foreach ($slots as $key => $value) {
            $slot = $kit->slot((string) $key);
            if ($slot === null || ! $slot->contentType->isText()) {
                continue;
            }
            $max = $slot->constraints->maxLength;
            if ($max === null) {
                continue;
            }

            if (is_string($value)) {
                $clamped = SlotLengthClamp::clamp($value, $max);
                if ($clamped !== $value) {
                    $slots[$key] = $clamped;
                    $truncated[] = (string) $key;
                }

                continue;
            }

            // Repeater / list: clamp each string item; an object item (faq etc.) has no per-field cap.
            if (is_array($value)) {
                $changed = false;
                foreach ($value as $i => $item) {
                    if (is_string($item)) {
                        $clamped = SlotLengthClamp::clamp($item, $max);
                        if ($clamped !== $item) {
                            $value[$i] = $clamped;
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    $slots[$key] = $value;
                    $truncated[] = (string) $key;
                }
            }
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
     * @param  list<string>  $truncated  slots truncated to their char budget (report fix 1)
     */
    private function persist(Content $page, PageGrounding $grounding, DraftPayload $payload, array $slots, array $headingFallbacks = [], array $truncated = []): void
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
        // Which slots were truncated to their char budget — the degrade record (never a silent cut).
        if ($truncated !== []) {
            $meta['slot_truncations'] = $truncated;
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
