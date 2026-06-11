<?php

namespace App\ContentEngine\Drafting;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\PageBuilder\Validation\KitValidator;
use App\PageBuilder\Validation\ValidationCode;
use App\PageBuilder\Validation\ValidationContext;
use App\PageBuilder\Validation\ValidationFailure;
use App\PageBuilder\Validation\ValidationResult;

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
        $structural = $this->structuralFailures(
            $this->validator->validate($kit, $slots, new ValidationContext($page)),
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

        $this->persist($page, $grounding, $attempt->payload, $slots);

        return $page;
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
     */
    private function persist(Content $page, PageGrounding $grounding, DraftPayload $payload, array $slots): void
    {
        $page->fill([
            'status' => ContentStatus::NeedsReview,
            'slot_payload' => $slots,
            'body' => null,
            'voice_profile_version' => $grounding->voiceProfileVersion,
            'wireframe_kit_version' => $grounding->kit->version,
            'meta' => [
                'seo' => $payload->seo->toArray(),
                'image_specs' => $payload->imageSpecsArray(),
            ],
        ])->save();
    }
}
