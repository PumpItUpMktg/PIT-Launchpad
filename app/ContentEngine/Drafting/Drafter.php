<?php

namespace App\ContentEngine\Drafting;

use App\Enums\ContentKind;
use App\PageBuilder\Schema\SlotDefinition;

/**
 * Drafts a POST from assembled news grounding: the prompt enforces the
 * Source/Claims split (business assertions only from the substantiated-claims
 * pool, cited by id; sources attribution-only; image slots yield SPECS). It owns
 * the post-shaped prompt only — the model call + JSON parse is the shared
 * DraftCall mechanism, and PageDrafter is its sibling for kit-slot pages.
 */
class Drafter
{
    public function __construct(
        private readonly DraftCall $call,
    ) {}

    public function draft(DraftRequest $request, Grounding $grounding): DraftPayload
    {
        return $this->attempt($request, $grounding)->payload;
    }

    /**
     * The full call: returns the raw model response alongside the parsed payload
     * (via the shared DraftCall).
     */
    public function attempt(DraftRequest $request, Grounding $grounding): DraftAttempt
    {
        return $this->call->attempt($this->system(), $this->prompt($request, $grounding));
    }

    /**
     * The exact system + user prompt this drafter would send — for the drafter
     * probe to show what goes to the model, without making the call.
     *
     * @return array{system: string, prompt: string}
     */
    public function preview(DraftRequest $request, Grounding $grounding): array
    {
        return ['system' => $this->system(), 'prompt' => $this->prompt($request, $grounding)];
    }

    private function system(): string
    {
        return 'You are a home-services content drafter. You write in the brand voice provided. '
            .'Accuracy is structural: you may assert a fact about the business ONLY if it is in the '
            .'SUBSTANTIATED CLAIMS pool, and you must cite the exact claim id you used. Material in the '
            .'SOURCES pool is background you may reference and attribute by name, but you must NEVER restate '
            .'it as a claim about the business. Frame problem→solution. Return ONLY sentinel-delimited '
            .'blocks as specified — no JSON, no prose, no code fences. Write content RAW between the markers: '
            .'never escape quotes or newlines.';
    }

    private function prompt(DraftRequest $request, Grounding $grounding): string
    {
        $parts = [];

        $parts[] = "Draft a {$request->kind->value} for a home-services brand.";
        if ($request->title !== null) {
            $parts[] = "Working title / target: {$request->title}";
        }
        if ($request->angleHint !== null && $request->angleHint !== '') {
            $parts[] = "Angle: {$request->angleHint}";
        }

        $parts[] = $this->voiceBlock($grounding);
        $parts[] = $this->claimsBlock($grounding);
        $parts[] = $this->sourcesBlock($grounding);
        $parts[] = $this->localBlock($grounding);
        $parts[] = $this->briefBlock($request);

        $parts[] = $request->kind === ContentKind::Page
            ? $this->kitBlock($grounding)
            : $this->postBlock();

        $parts[] = $this->outputContract($request);

        return implode("\n\n", array_filter($parts));
    }

    private function voiceBlock(Grounding $grounding): string
    {
        if ($grounding->voiceProfile === []) {
            return 'VOICE: default brand voice (warm, plain, confident; first-person plural "we").';
        }

        return "VOICE PROFILE (write in this voice):\n".json_encode($grounding->voiceProfile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function claimsBlock(Grounding $grounding): string
    {
        if ($grounding->claims === []) {
            return "SUBSTANTIATED CLAIMS pool: (empty)\n"
                .'You have NO substantiated claims — do not assert any specific fact about the business '
                .'(no guarantees, certifications, stats, or awards).';
        }

        $lines = array_map(fn (Claim $c) => $c->promptLine(), $grounding->claims);

        return 'SUBSTANTIATED CLAIMS pool — the ONLY facts you may assert about the business. '
            ."Cite the [id] of each one you use in claims_used:\n".implode("\n", $lines);
    }

    private function sourcesBlock(Grounding $grounding): string
    {
        if ($grounding->sources === []) {
            return 'SOURCES pool: (none)';
        }

        $lines = array_map(fn (SourceRef $s) => $s->promptLine(), $grounding->sources);

        return "SOURCES pool — background/context only. Attribute by name; NEVER restate as a business claim:\n"
            .implode("\n", $lines);
    }

    private function localBlock(Grounding $grounding): string
    {
        if (! $grounding->localInjectionAllowed || $grounding->towns === []) {
            return 'LOCALIZATION: none — keep the copy town-agnostic. Do not name specific towns or neighborhoods.';
        }

        return 'LOCALIZATION: this is a locally-relevant reactive piece. You MAY naturally reference these towns: '
            .implode(', ', $grounding->towns).'. List any you used in "towns".';
    }

    private function briefBlock(DraftRequest $request): string
    {
        if ($request->brief === []) {
            return '';
        }

        $keep = array_intersect_key($request->brief, array_flip([
            'intent', 'problem_framing', 'coverage_requirements', 'proof_hooks',
            'differentiation_angle', 'cta_intent', 'seo_targets', 'alt_keywords',
        ]));

        return "DIRECTED BRIEF (structural guidance — execute it):\n"
            .json_encode($keep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function kitBlock(Grounding $grounding): string
    {
        if ($grounding->kit === null) {
            return 'KIT: none provided — return a single "body" string instead of slots.';
        }

        $lines = [];
        foreach ($grounding->kit->slots as $slot) {
            $lines[] = $this->slotLine($slot);
        }

        return 'KIT SLOTS — emit one block per slot, keyed by its slot key. Respect content_type, role '
            ."(problem→solution arc), and cardinality (repeat a slot's block once per item):\n".implode("\n", $lines)."\n\n"
            .'For image/gallery slots: do NOT render. Emit an image SPEC block instead (image.<slot>) and leave the slot out.';
    }

    private function slotLine(SlotDefinition $slot): string
    {
        $card = $slot->isRepeater()
            ? "repeater {$slot->cardinality->min}..{$slot->cardinality->max}"
            : 'single';
        $req = $slot->isRequired() ? 'required' : 'optional';
        $grounded = $slot->source->resolvesAgainstEntities()
            ? ' [GROUNDED: only substantiated claims]'
            : '';
        $hint = $slot->generationHint !== null ? " — {$slot->generationHint}" : '';

        return "- {$slot->key} ({$slot->contentType->value}, role={$slot->role->value}, {$card}, {$req}){$grounded}{$hint}";
    }

    private function postBlock(): string
    {
        return 'POST: write the article into the `body` block as clean HTML. Open by framing the reader\'s '
            .'problem, then deliver the solution. Attribute any source material by name.';
    }

    private function outputContract(DraftRequest $request): string
    {
        $contentBlocks = $request->kind === ContentKind::Page
            ? "<<<SLOT:hero_problem>>>\n…value (repeat a slot's block once per repeater item)…\n<<<END>>>"
            : "<<<SLOT:body>>>\n…the full article as clean HTML…\n<<<END>>>";

        return SentinelContract::describe(
            'Return ONLY sentinel-delimited blocks — no JSON, no prose, no code fences. Write each value RAW '
            .'between the markers (do not escape quotes or newlines). A block is:',
            $contentBlocks,
        );
    }
}
