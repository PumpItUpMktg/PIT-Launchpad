<?php

namespace App\ContentEngine\Drafting;

use App\Enums\SlotContentType;
use App\PageBuilder\Schema\SlotDefinition;

/**
 * Drafts a PAGE from intake-entity grounding: the prompt carries the kit's slot
 * schema and the site's services/problems/offers/markets/proof/branding + voice,
 * and asks for ONE JSON object keyed exactly by slot key (image slots emit specs,
 * not values). Sibling of the post Drafter — it owns only the page prompt; the
 * model call + parse is the shared DraftCall, so there is no second client and no
 * second parser. Output is a DraftPayload (slots + seo + images + claims_used),
 * validated against the kit schema by the engine before it persists.
 */
class PageDrafter
{
    public function __construct(
        private readonly DraftCall $call,
    ) {}

    public function attempt(PageGrounding $grounding): DraftAttempt
    {
        return $this->call->attempt($this->system(), $this->prompt($grounding));
    }

    /**
     * The exact system + user prompt — for the drafter probe, without the call.
     *
     * @return array{system: string, prompt: string}
     */
    public function preview(PageGrounding $grounding): array
    {
        return ['system' => $this->system(), 'prompt' => $this->prompt($grounding)];
    }

    private function system(): string
    {
        return 'You are a home-services website page builder. You write in the brand voice provided. '
            .'Accuracy is structural: you may assert a fact about the business ONLY if it is in the '
            .'SUBSTANTIATED PROOF pool, and you must cite the exact proof id you used. Reference locality '
            .'ONLY from the MARKETS provided — never invent towns, neighborhoods, or service areas. Fill '
            .'EVERY required slot, keyed EXACTLY by its slot key (an off-schema key renders as a blank '
            .'section). Frame problem→solution. Return ONLY sentinel-delimited blocks as specified — no JSON, '
            .'no prose, no code fences. Write each value RAW between the markers: never escape quotes or newlines.';
    }

    private function prompt(PageGrounding $grounding): string
    {
        $parts = [];

        $parts[] = "Build a {$grounding->pageType->value} page for a home-services brand.";
        if ($grounding->targetKeyword !== null && $grounding->targetKeyword !== '') {
            $parts[] = "Primary target: {$grounding->targetKeyword}";
        }

        $parts[] = $this->voiceBlock($grounding);
        $parts[] = $this->brandingBlock($grounding);
        $parts[] = $this->servicesBlock($grounding);
        $parts[] = $this->problemsBlock($grounding);
        $parts[] = $this->offersBlock($grounding);
        $parts[] = $this->marketsBlock($grounding);
        $parts[] = $this->proofBlock($grounding);
        $parts[] = $this->kitBlock($grounding);
        $parts[] = $this->outputContract();

        return implode("\n\n", array_filter($parts));
    }

    private function voiceBlock(PageGrounding $grounding): string
    {
        if ($grounding->voiceProfile === []) {
            return 'VOICE: default brand voice (warm, plain, confident; first-person plural "we").';
        }

        return "VOICE PROFILE (write in this voice):\n".$this->json($grounding->voiceProfile);
    }

    private function brandingBlock(PageGrounding $grounding): string
    {
        return "BRAND / NAP (use exactly; never invent):\n".$this->json($grounding->branding);
    }

    private function servicesBlock(PageGrounding $grounding): string
    {
        if ($grounding->services === []) {
            return 'SERVICES: none provided — keep the copy to the brand and its proof.';
        }

        return "SERVICES in scope for this page:\n".$this->json($grounding->services);
    }

    private function problemsBlock(PageGrounding $grounding): string
    {
        if ($grounding->problems === []) {
            return '';
        }

        return "CUSTOMER PROBLEMS to frame (problem→solution):\n".$this->json($grounding->problems);
    }

    private function offersBlock(PageGrounding $grounding): string
    {
        if ($grounding->offers === []) {
            return '';
        }

        return "OFFERS you may reference (verbatim terms):\n".$this->json($grounding->offers);
    }

    private function marketsBlock(PageGrounding $grounding): string
    {
        if ($grounding->markets === []) {
            return 'MARKETS: none provided — keep the copy geo-neutral; do not name any town.';
        }

        return "MARKETS — the ONLY locality you may reference (honest coverage data):\n".$this->json($grounding->markets);
    }

    private function proofBlock(PageGrounding $grounding): string
    {
        if ($grounding->proof === []) {
            return "SUBSTANTIATED PROOF pool: (empty)\n"
                .'You have NO substantiated proof — do not assert any specific fact about the business '
                .'(no guarantees, certifications, stats, or awards).';
        }

        $lines = array_map(fn (Claim $c) => $c->promptLine(), $grounding->proof);

        return 'SUBSTANTIATED PROOF pool — the ONLY facts you may assert. Cite the [id] of each one you use '
            ."in claims_used:\n".implode("\n", $lines);
    }

    private function kitBlock(PageGrounding $grounding): string
    {
        $lines = [];
        foreach ($grounding->kit->slots as $slot) {
            $lines[] = $this->slotLine($slot);
        }

        return 'KIT SLOTS — emit one block per slot, keyed by its EXACT slot key. Respect content_type, role '
            ."(problem→solution arc), and cardinality (repeat a slot's block once per item):\n".implode("\n", $lines)."\n\n"
            .'For image/gallery slots: do NOT render. Emit an image SPEC block instead (image.<slot>) and leave the slot out. '
            .'Entity-sourced slots (the platform fills them) you may omit.';
    }

    private function slotLine(SlotDefinition $slot): string
    {
        $card = $slot->isRepeater()
            ? "repeater {$slot->cardinality->min}..{$slot->cardinality->max}"
            : 'single';
        $req = $slot->isRequired() ? 'required' : 'optional';
        $grounded = $slot->source->resolvesAgainstEntities()
            ? ' [GROUNDED: only substantiated proof]'
            : '';
        $hint = $slot->generationHint !== null ? " — {$slot->generationHint}" : '';
        $fields = $this->fieldOrder($slot);

        return "- {$slot->key} ({$slot->contentType->value}, role={$slot->role->value}, {$card}, {$req}){$grounded}{$fields}{$hint}";
    }

    /**
     * For object content types, declare the `||` sub-field order the SlotShaper
     * re-keys (plain-text/list slots carry their value whole — no delimiter).
     */
    private function fieldOrder(SlotDefinition $slot): string
    {
        $order = match ($slot->contentType) {
            SlotContentType::Faq => 'question || answer',
            SlotContentType::Stat => 'value || label',
            SlotContentType::Testimonial => 'quote || author',
            SlotContentType::Cta => 'label || url',
            default => null,
        };

        return $order !== null ? " [fields: {$order}]" : '';
    }

    private function outputContract(): string
    {
        return SentinelContract::describe(
            'Return ONLY sentinel-delimited blocks — no JSON, no prose, no code fences. Write each value RAW '
            .'between the markers (do not escape quotes or newlines). A block is:',
            "<<<SLOT:hero_problem>>>\n…value (repeat a slot's block once per repeater item)…\n<<<END>>>",
        );
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
