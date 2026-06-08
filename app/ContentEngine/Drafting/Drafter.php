<?php

namespace App\ContentEngine\Drafting;

use App\Enums\ContentKind;
use App\Integrations\Claude\ClaudeClient;
use App\PageBuilder\Schema\SlotDefinition;

/**
 * Drafts content from assembled grounding via the Sonnet-configured ClaudeClient
 * seam (mockable in tests). The prompt enforces the Source/Claims split: business
 * assertions may come ONLY from the substantiated-claims pool (cited by id),
 * sources are attribution-only, and image slots yield SPECS, never renders. A
 * page draft fills the kit's slots; a post draft fills a body. Output is strict
 * JSON, parsed into a DraftPayload.
 */
class Drafter
{
    public function __construct(
        private readonly ClaudeClient $claude,
    ) {}

    public function draft(DraftRequest $request, Grounding $grounding): DraftPayload
    {
        $response = $this->claude->complete($this->prompt($request, $grounding), $this->system());

        return DraftPayload::fromArray($this->parse($response));
    }

    private function system(): string
    {
        return 'You are a home-services content drafter. You write in the brand voice provided. '
            .'Accuracy is structural: you may assert a fact about the business ONLY if it is in the '
            .'SUBSTANTIATED CLAIMS pool, and you must cite the exact claim id you used. Material in the '
            .'SOURCES pool is background you may reference and attribute by name, but you must NEVER restate '
            .'it as a claim about the business. Frame problem→solution. Return strict JSON only — no prose, '
            .'no code fences.';
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

        return 'KIT SLOTS — fill each by its key in "slots". Respect content_type, role (problem→solution arc), '
            ."and cardinality (a repeater expects an array):\n".implode("\n", $lines)."\n\n"
            .'For image/gallery slots: do NOT render. Emit a SPEC into "images" (slot, prompt, seo_filename, alt, title, caption) '
            .'and leave the slot value out.';
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
        return 'POST: write the article into "body" as clean HTML. Open by framing the reader\'s problem, '
            .'then deliver the solution. Attribute any source material by name.';
    }

    private function outputContract(DraftRequest $request): string
    {
        $shape = $request->kind === ContentKind::Page
            ? '"slots": { "<slot_key>": <value-or-array>, ... }'
            : '"body": "<html>"';

        return "Return ONLY this JSON object:\n"
            ."{\n"
            ."  {$shape},\n"
            .'  "seo": {"title":"...","meta_description":"...","slug":"...","og_title":"...","og_description":"...","twitter_title":"...","twitter_description":"..."},'."\n"
            .'  "images": [{"slot":"...","prompt":"...","seo_filename":"...","alt":"...","title":"...","caption":"..."}],'."\n"
            .'  "claims_used": [{"text":"<assertion as written>","claim_id":"<id from the claims pool>"}],'."\n"
            .'  "sources_cited": [{"name":"<source name>","url":"<canonical url or null>"}],'."\n"
            .'  "towns": ["<town>", ...]'."\n"
            .'}';
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
