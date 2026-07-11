<?php

namespace App\Interview\Expansion;

use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Interview\InterviewExtractor;
use App\Interview\SiloSeed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The headless problem-chain silo expander (Phase 2). Turns a confirmed SiloSeed +
 * VoiceProfile into the validated candidate tree: silos (pillar + spokes) reasoned
 * across five dimensions — equipment×action matrix, problem-chain adjacencies
 * (cross-trade), upstream content pages, a parallel audience silo, a brand silo — plus
 * a fringe handoff set for the Routing layer. Maximal split, volume-pending; Phase 3
 * attaches volume and Phase 4 prunes.
 *
 * Mirrors {@see InterviewExtractor} discipline: strict schema, validate,
 * retry-then-throw, no fabrication. The bound client prefills "{" (raw JSON, no fences)
 * on a generous token budget; the parse is fence/array tolerant; and every failed
 * attempt logs the raw response (stop_reason + tokens) so a live divergence is
 * diagnosable rather than blind. {@see expandDecomposed} is the per-silo fallback when
 * a single call can't reliably hold/parse the full tree.
 */
final class SiloExpander
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly ExpansionValidator $validator,
        private readonly int $maxAttempts = 2,
    ) {}

    /**
     * Single-call expansion: the whole tree in one strict-schema response.
     *
     * @param  array<string, mixed>  $voice
     *
     * @throws ExpansionException
     */
    public function expand(SiloSeed $seed, array $voice = []): ExpansionResult
    {
        $payload = $this->call(
            $this->prompt($seed, $voice),
            fn (mixed $p) => $this->validator->validate($p),
            'single',
        );

        return ExpansionResult::fromArray($payload);
    }

    /**
     * Decomposed expansion: one call for the silo plan (headers + fringe), then one
     * call per silo for its spokes. Smaller JSON per call is far more robust at the
     * SPG calibration size.
     *
     * @param  array<string, mixed>  $voice
     *
     * @throws ExpansionException
     */
    public function expandDecomposed(SiloSeed $seed, array $voice = []): ExpansionResult
    {
        $plan = $this->call(
            $this->planPrompt($seed, $voice),
            fn (mixed $p) => $this->validator->validatePlan($p),
            'plan',
        );

        $silos = [];
        foreach (array_filter($plan['silos'], 'is_array') as $planned) {
            $name = trim((string) ($planned['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $spokes = $this->call(
                $this->siloPrompt($seed, $voice, $planned),
                fn (mixed $p) => $this->validator->validateSpokes($p),
                'silo:'.$name,
            );

            $silos[] = CandidateSilo::fromArray([
                'name' => $name,
                'head_keyword' => $planned['head_keyword'] ?? '',
                'page_type' => $planned['page_type'] ?? 'service',
                'spokes' => $spokes['spokes'],
            ]);
        }

        $fringe = is_array($plan['fringe_handoff'] ?? null)
            ? array_values(array_map(fn (array $f) => FringeCandidate::fromArray($f), array_filter($plan['fringe_handoff'], 'is_array')))
            : [];

        return new ExpansionResult($silos, $fringe);
    }

    /**
     * Run a prompt with validate → retry → throw, logging the raw response on each
     * failed attempt so a live divergence (fenced / truncated / wrong shape) is visible.
     *
     * @param  callable(mixed): list<string>  $validate
     * @return array<string, mixed>
     *
     * @throws ExpansionException
     */
    private function call(string $prompt, callable $validate, string $mode): array
    {
        $errors = ['No model response.'];

        for ($attempt = 1; $attempt <= max(1, $this->maxAttempts); $attempt++) {
            $result = $this->claude->completeDetailed($prompt, $this->system());
            $payload = $this->decode($result->text);
            $errors = $validate($payload);

            if ($errors === [] && is_array($payload)) {
                return $payload;
            }

            $this->logFailure($mode, $attempt, $result, $errors);
        }

        throw new ExpansionException(
            "Could not expand a well-formed tree ({$mode}): ".implode(' ', $errors),
            $errors,
        );
    }

    private function logFailure(string $mode, int $attempt, CompletionResult $result, array $errors): void
    {
        Log::warning('SiloExpander validation failed', [
            'mode' => $mode,
            'attempt' => $attempt,
            'stop_reason' => $result->stopReason,
            'output_tokens' => $result->outputTokens,
            'errors' => $errors,
            'raw' => Str::limit($result->text, 4000),
        ]);
    }

    /**
     * Tolerant decode: strip markdown fences, then parse — accepting either the whole
     * string, or the outermost {...} / [...] span. A legitimate top-level array is
     * reconciled to the required {"silos":[...]} shape.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $response): ?array
    {
        $decoded = $this->extract($this->stripFences($response));
        if ($decoded === null) {
            return null;
        }

        return array_is_list($decoded) ? ['silos' => $decoded] : $decoded;
    }

    private function stripFences(string $s): string
    {
        $s = trim($s);
        $s = (string) preg_replace('/^```(?:json)?\s*/i', '', $s);

        return trim((string) preg_replace('/\s*```$/', '', $s));
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function extract(string $s): ?array
    {
        $whole = json_decode(trim($s), true);
        if (is_array($whole)) {
            return $whole;
        }

        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $start = strpos($s, $open);
            $end = strrpos($s, $close);
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($s, $start, $end - $start + 1), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    private function system(): string
    {
        return 'You are an SEO content architect for local-service businesses. From a confirmed business seed '
            .'you expand a deep, methodical candidate page tree by reasoning about the CUSTOMER\'S PROBLEM '
            .'(causes upstream → the core fix → effects downstream), not just the owner\'s stated service category. '
            .'A service the owner forgot to mention is a lost lead, so be generous: propose the maximal split now — '
            .'a low-value page is pruned later, a missing one is gone forever. '
            .'OUTPUT CONTRACT: your ENTIRE response must be one raw JSON object — start with "{" and end with "}". '
            .'No markdown, no code fences, no preamble, no commentary, no trailing text.';
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function prompt(SiloSeed $seed, array $voice): string
    {
        return $this->seedContext($seed, $voice)."\n\n"
            .$this->dimensions()."\n\n"
            .$this->tagging()."\n\n"
            .'RULES: every spoke needs a concise, geo-neutral head_keyword. '.$this->keywordIntentRule().' '.$this->intentTagRule().' granularity = "own_page" for all (maximal split; volume folds later). Audience and brand are SILOS, not flags. Do not invent services that contradict the exclusions.'."\n\n"
            .'Respond with ONLY this JSON shape:'."\n"
            .'{"silos":[{"name":"Sump Pumps","head_keyword":"sump pump","page_type":"service","spokes":[{"name":"Sump Pump Installation","page_type":"service","tag":"core","head_keyword":"sump pump installation","connection_note":null,"granularity":"own_page","intent":"transactional"}]}],"fringe_handoff":[{"name":"Mold Remediation","connection_note":"mold from chronic basement moisture","sibling_brand":"Trusted Mold"}]}';
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function planPrompt(SiloSeed $seed, array $voice): string
    {
        return $this->seedContext($seed, $voice)."\n\n"
            .$this->dimensions()."\n\n"
            .'PLAN ONLY: list the SILOS you will build (pillar headers — including the audience and brand silos) and the fringe handoff set. Do NOT list spokes yet. '.$this->keywordIntentRule()."\n\n"
            .'Respond with ONLY this JSON shape:'."\n"
            .'{"silos":[{"name":"Sump Pumps","head_keyword":"sump pump","page_type":"service","focus":"sump pump equipment x action"}],"fringe_handoff":[{"name":"Mold Remediation","connection_note":"mold from chronic basement moisture","sibling_brand":"Trusted Mold"}]}';
    }

    /**
     * @param  array<string, mixed>  $voice
     * @param  array<string, mixed>  $silo
     */
    private function siloPrompt(SiloSeed $seed, array $voice, array $silo): string
    {
        $name = (string) ($silo['name'] ?? '');
        $focus = (string) ($silo['focus'] ?? '');

        return $this->seedContext($seed, $voice)."\n\n"
            .'Expand ONLY the spokes for this one silo:'."\n"
            ."- Silo: {$name}\n"
            .($focus !== '' ? "- Focus: {$focus}\n" : '')
            .'Apply the equipment×action matrix and problem-chain adjacencies WITHIN this silo. '.$this->tagging()."\n\n"
            .'RULES: every spoke needs a concise, geo-neutral head_keyword; '.$this->keywordIntentRule().' '.$this->intentTagRule().' granularity = "own_page"; a connecting spoke REQUIRES a connection_note.'."\n\n"
            .'Respond with ONLY this JSON shape:'."\n"
            .'{"spokes":[{"name":"Sump Pump Installation","page_type":"service","tag":"core","head_keyword":"sump pump installation","connection_note":null,"granularity":"own_page","intent":"transactional"}]}';
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function seedContext(SiloSeed $seed, array $voice): string
    {
        $anchors = $seed->anchorServices === [] ? '(none given)' : implode(', ', $seed->anchorServices);
        $exclusions = $seed->exclusions === [] ? '(none given)' : implode(', ', $seed->exclusions);
        $gbp = $seed->gbpSignals === null || $seed->gbpSignals === []
            ? 'Not connected — rely on the seed + your trade knowledge.'
            : implode(', ', $seed->gbpSignals);
        $region = $seed->region === '' ? '(not stated)' : $seed->region;
        $voiceJson = $voice === [] ? '{}' : (string) json_encode($voice, JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
            BUSINESS SEED
            - Trade: {$seed->trade}
            - Anchor services (a FEW the owner named — NOT exhaustive): {$anchors}
            - Broad region (positioning only — do NOT put city/state terms in any name or head_keyword; geography is a separate Locations layer): {$region}
            - Exclusions (HARD lane boundary — never propose these as core/adjacent/connecting service pages): {$exclusions}
            - GBP signals (ground truth for what they DO — seed the `core` set from these): {$gbp}
            - Voice profile (mine for a SECONDARY AUDIENCE signal e.g. "commercial clientele", and for BRAND names the owner installs/services): {$voiceJson}
            PROMPT;
    }

    private function dimensions(): string
    {
        return <<<'PROMPT'
            EXPAND across these dimensions:
            1. EQUIPMENT × ACTION matrix (the biggest multiplier): for each core equipment/service, fan out the actions that GENUINELY apply — install, replace, repair (incl. any-brand), maintenance, monitoring/alarms, backup, 24/7 emergency, troubleshooting/common-problems. Reason which actions are real per equipment; do not force every action onto every type.
            2. PROBLEM-CHAIN ADJACENCIES (often cross-trade): reason causes → fix → effects and propose the related services (e.g. for basement water: crawl space, interior/exterior waterproofing, foundation crack, french drains, gutters, yard drainage, leak detection, radon). Tag `connecting` and give a connection_note ("gutters — a cause of basement water").
            3. UPSTREAM CONTENT pages: symptom/problem-aware searcher pages that capture upstream and route to the core service ("Why is my basement wet?", "Common problems & solutions"). Set page_type=content.
            4. AUDIENCE axis: if a secondary audience is signaled, emit a PARALLEL audience silo (e.g. "Commercial & Industrial") with its own equipment×action spokes.
            5. BRAND axis: if the owner names brands, emit a "Brands We Service" silo with a spoke per brand.
            PROMPT;
    }

    /**
     * Head keywords must name the hire-able SERVICE, not a bare product the user would shop for.
     * A product/equipment noun ("dehumidifier", "french drain") pulls retail/shopping volume
     * that misrepresents service demand and must never anchor an own-page spoke. (E.g.
     * "basement dehumidification", not "basement dehumidifier"; "french drain installation",
     * not "french drain".)
     */
    /** The intent TAG on every spoke — the longtail router's input (one extra field, no new pass). */
    private function intentTagRule(): string
    {
        return 'Tag every spoke with "intent": "transactional" (hire/buy — "…installation near me"), '
            .'"commercial" (evaluating — "best …", "cost of …"), or "informational" (learning — '
            .'"why is my basement wet in spring").';
    }

    private function keywordIntentRule(): string
    {
        return 'SERVICE-INTENT head_keyword: name the SERVICE a customer hires for (the action/outcome), '
            .'NOT a bare product/equipment noun a shopper buys — a product noun (e.g. "dehumidifier", '
            .'"french drain") pulls retail/shopping volume that misrepresents service demand. Anchor on the '
            .'service phrase ("basement dehumidification", "french drain installation"), never the product alone.';
    }

    private function tagging(): string
    {
        return 'TAGGING (use exactly): core = confirmed offering (matches seed/GBP); adjacent = related service within the trade; '
            .'connecting = problem-chain service, often cross-trade (connection_note REQUIRED); fringe = genuinely out-of-lane/peripheral. '
            .'FRINGE: do NOT make fringe service pages — put each out-of-lane item in `fringe_handoff` with a connection_note and, if it maps to a sibling brand/partner, a sibling_brand hint (e.g. mold → "Trusted Mold").';
    }
}
