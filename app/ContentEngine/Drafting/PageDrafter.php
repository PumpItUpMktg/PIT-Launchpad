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
            .'ONLY from the MARKETS provided — never invent towns, neighborhoods, or service areas. When you '
            .'link to another page on this site, use ONLY a path from INTERNAL LINKS — never invent a URL or '
            .'leave a placeholder. Fill '
            .'EVERY required slot, keyed EXACTLY by its slot key (an off-schema key renders as a blank '
            .'section). Frame problem→solution. Return ONLY sentinel-delimited blocks as specified — no JSON, '
            .'no prose, no code fences. Write each value RAW between the markers: never escape quotes or newlines.';
    }

    private function prompt(PageGrounding $grounding): string
    {
        $parts = [];

        $descriptor = $grounding->pageLabel !== null
            ? "\"{$grounding->pageLabel}\" page"
            : "{$grounding->pageType->value} page";
        $parts[] = "Build the {$descriptor} for a home-services brand.";
        if ($grounding->targetKeyword !== null && $grounding->targetKeyword !== '') {
            $parts[] = "Primary target: {$grounding->targetKeyword}";
        }

        $parts[] = $this->voiceBlock($grounding);
        $parts[] = $this->brandingBlock($grounding);
        $parts[] = $this->narrativeBlock($grounding);
        $parts[] = $this->servicesBlock($grounding);
        $parts[] = $this->problemsBlock($grounding);
        $parts[] = $this->offersBlock($grounding);
        $parts[] = $this->marketsBlock($grounding);
        $parts[] = $this->locationBlock($grounding);
        $parts[] = $this->factsBlock($grounding);
        $parts[] = $this->proofBlock($grounding);
        $parts[] = $this->internalLinksBlock($grounding);
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

    private function narrativeBlock(PageGrounding $grounding): string
    {
        if ($grounding->narrative === []) {
            return '';
        }

        return 'BRAND NARRATIVE (the captured intake this page is built from — write it in the brand '
            .'voice, expand and shape, but DO NOT invent facts, history, numbers, names, or claims '
            ."beyond what is here):\n".$this->json($grounding->narrative);
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

    /**
     * A location page's subject block: the business location this page is about. market_notes is
     * the operator's own local knowledge — trusted verbatim as source material; local_facts are
     * fetched data (climate/terrain/census) to weave naturally into prose, never dump. Anything
     * local NOT in this block does not exist for the draft.
     */
    private function locationBlock(PageGrounding $grounding): string
    {
        if ($grounding->location === []) {
            return '';
        }

        return 'LOCATION — this page\'s subject (a real business location). These are the ONLY local '
            .'facts you may use: name only the city and served_towns given; cite local_facts naturally '
            .'in prose (never as a data dump); treat market_notes as the owner\'s own local knowledge '
            .'and work it in faithfully. NEVER invent local details — no water tables, soil types, '
            .'weather patterns, landmarks, or years-serving-this-town beyond what is here:'
            ."\n".$this->json($grounding->location);
    }

    private function factsBlock(PageGrounding $grounding): string
    {
        if ($grounding->facts === []) {
            return 'OPERATIONAL FACTS: none provided — make NO operational claims '
                .'(no hours, no emergency availability, no response times).';
        }

        return 'OPERATIONAL FACTS — the ONLY operational claims you may make (hours, emergency '
            .'availability, contact channels). An absent fact must not be mentioned; if '
            .'offers_emergency_service is false, say so honestly when the question calls for it:'
            ."\n".$this->json($grounding->facts);
    }

    private function proofBlock(PageGrounding $grounding): string
    {
        if ($grounding->proof === []) {
            return "SUBSTANTIATED PROOF pool: (empty)\n"
                .'You have NO substantiated proof — do not assert any specific fact about the business '
                .'(no guarantees, certifications, stats, or awards).';
        }

        $lines = array_map(fn (Claim $c) => $c->promptLine(), $grounding->proof);

        return 'SUBSTANTIATED PROOF pool — the ONLY facts you may assert:'."\n"
            .implode("\n", $lines)."\n\n"
            .DraftCall::PROOF_RULES;
    }

    private function internalLinksBlock(PageGrounding $grounding): string
    {
        if ($grounding->relatedLinks === []) {
            return '';
        }

        $lines = array_map(fn (array $l) => "- {$l['anchor']} → {$l['path']}", $grounding->relatedLinks);

        return 'INTERNAL LINKS — other pages on this site, each with its FINAL path. Weave a few relevant ones '
            .'into the body as real links (e.g. <a href="/the-path">anchor</a>): the parent/related service, '
            .'nearby areas, or pages a reader would go to next. Use ONLY these exact paths — never invent a URL '
            .'or leave a placeholder. A target may not be published yet; link it anyway (it goes live as that '
            ."page is built):\n".implode("\n", $lines);
    }

    private function kitBlock(PageGrounding $grounding): string
    {
        $lines = [];
        foreach ($grounding->kit->slots as $slot) {
            $lines[] = $this->slotLine($slot);
        }

        return 'KIT SLOTS — emit one block per slot, keyed by its EXACT slot key. Respect content_type, role '
            ."(problem→solution arc), and cardinality (repeat a slot's block once per item):\n".implode("\n", $lines)."\n\n"
            .'CHARACTER BUDGETS are hard limits: a slot line\'s "N–M chars — write to ~T" means the '
            .'validator REJECTS the whole draft if that slot exceeds M. Write to the ~T target; never run '
            .'past M. '
            .'For image/gallery slots: do NOT render. Emit an image SPEC block instead (image.<slot>) and leave the slot out. '
            .'Entity-sourced slots (the platform fills them) you may omit. '
            .'Inside a body/rich-text slot, do NOT use an H1 or H2 heading — the page section already supplies its '
            .'heading; use H3 or lower for any sub-heading within the copy.';
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

        return "- {$slot->key} ({$slot->contentType->value}, role={$slot->role->value}, {$card}, {$req}{$this->charBudget($slot)}){$grounded}{$fields}{$hint}";
    }

    /**
     * The slot's character budget, rendered INTO the prompt so the model writes within it — the kit
     * validator hard-rejects an over-budget draft (the whole page fails, not just the slot), so
     * generating blind to the cap is a failure loop. Empty for unconstrained slots.
     */
    private function charBudget(SlotDefinition $slot): string
    {
        $min = $slot->constraints->minLength;
        $max = $slot->constraints->maxLength;

        if ($min === null && $max === null) {
            return '';
        }
        if ($max === null) {
            return ", min {$min} chars";
        }

        // An explicit write-to target ~80% of the cap leaves headroom for the model's tendency to run long.
        $target = max((int) floor($max * 0.8), $min ?? 0);

        return ', '.($min !== null ? "{$min}–{$max}" : "max {$max}")." chars — write to ~{$target}";
    }

    /**
     * For object content types, declare the `||` sub-field order the SlotShaper
     * re-keys (plain-text/list slots carry their value whole — no delimiter).
     */
    private function fieldOrder(SlotDefinition $slot): string
    {
        // Angle-bracket PLACEHOLDERS, not bare field-name tokens: a literal
        // "question || answer" hint got echoed by the model as labeled lines
        // ("question || <q>\nanswer || <a>"), which the shaper then mis-split.
        $order = match ($slot->contentType) {
            SlotContentType::Faq => '<question text> || <answer text>',
            SlotContentType::Stat => '<value> || <label>',
            SlotContentType::Testimonial => '<quote> || <author>',
            SlotContentType::Cta => '<label> || <url>',
            default => null,
        };

        return $order !== null ? " [ONE line, fill in order: {$order} — do NOT write the field names]" : '';
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
