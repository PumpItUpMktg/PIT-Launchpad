<?php

namespace App\Interview\Expansion;

use App\Integrations\Claude\ClaudeClient;
use App\Interview\InterviewExtractor;
use App\Interview\SiloSeed;

/**
 * The headless problem-chain silo expander (Phase 2). One strict-schema Claude call
 * turns a confirmed SiloSeed + VoiceProfile into the validated candidate tree:
 * silos (pillar + spokes) reasoned across five dimensions — equipment×action matrix,
 * problem-chain adjacencies (cross-trade), upstream content pages, a parallel audience
 * silo, a brand silo — plus a fringe handoff set for the Routing layer. Maximal split,
 * volume-pending; Phase 3 attaches volume and Phase 4 prunes.
 *
 * Mirrors {@see InterviewExtractor} discipline: strict schema, validate,
 * retry-then-throw, no fabrication. GBP (when connected) seeds the `core` set; the
 * problem-chain + dimensions generate the capturable set. Exclusions hard-bound the lane.
 */
final class SiloExpander
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly ExpansionValidator $validator,
        private readonly int $maxAttempts = 2,
    ) {}

    /**
     * @param  array<string, mixed>  $voice  the VoiceProfile payload (mined for secondary-audience + brand signals)
     *
     * @throws ExpansionException when no attempt yields a valid candidate tree
     */
    public function expand(SiloSeed $seed, array $voice = []): ExpansionResult
    {
        $system = $this->system();
        $prompt = $this->prompt($seed, $voice);

        $errors = ['No model response.'];

        for ($attempt = 1; $attempt <= max(1, $this->maxAttempts); $attempt++) {
            $payload = $this->decode($this->claude->complete($prompt, $system));
            $errors = $this->validator->validate($payload);

            if ($errors === [] && is_array($payload)) {
                return ExpansionResult::fromArray($payload);
            }
        }

        throw new ExpansionException(
            'Could not expand a well-formed candidate tree: '.implode(' ', $errors),
            $errors,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $response): ?array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function system(): string
    {
        return 'You are an SEO content architect for local-service businesses. From a confirmed business seed '
            .'you expand a deep, methodical candidate page tree by reasoning about the CUSTOMER\'S PROBLEM '
            .'(causes upstream → the core fix → effects downstream), not just the owner\'s stated service category. '
            .'A service the owner forgot to mention is a lost lead, so be generous: propose the maximal split now — '
            .'a low-value page is pruned later, a missing one is gone forever. You return STRICT JSON only — never '
            .'prose, never markdown fences.';
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function prompt(SiloSeed $seed, array $voice): string
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
            - Broad region (positioning only — do NOT put city/state terms in any name or head_keyword; geography is handled by a separate Locations layer): {$region}
            - Exclusions (HARD lane boundary — never propose these as core/adjacent/connecting service pages): {$exclusions}
            - GBP signals (ground truth for what they DO — seed the `core` set from these): {$gbp}
            - Voice profile (mine for a SECONDARY AUDIENCE signal e.g. "commercial clientele", and for BRAND names the owner installs/services): {$voiceJson}

            EXPAND across these dimensions:
            1. EQUIPMENT × ACTION matrix (the biggest multiplier): for each core equipment/service, fan out the actions that GENUINELY apply — install, replace, repair (incl. any-brand), maintenance, monitoring/alarms, backup, 24/7 emergency, troubleshooting/common-problems. Reason which actions are real per equipment; do not force every action onto every type.
            2. PROBLEM-CHAIN ADJACENCIES (often cross-trade): reason causes → fix → effects and propose the related services (e.g. for basement water: crawl space, interior/exterior waterproofing, foundation crack, french drains, gutters, yard drainage, leak detection, radon). Tag `connecting` and give a connection_note ("gutters — a cause of basement water").
            3. UPSTREAM CONTENT pages: symptom/problem-aware searcher pages that capture upstream and route to the core service ("Why is my basement wet?", "Common problems & solutions"). Set page_type=content.
            4. AUDIENCE axis: if a secondary audience is signaled, emit a PARALLEL audience silo (e.g. "Commercial & Industrial") with its own equipment×action spokes.
            5. BRAND axis: if the owner names brands, emit a "Brands We Service" silo with a spoke per brand.

            TAGGING (use exactly): core = confirmed offering (matches seed/GBP); adjacent = related service within the trade; connecting = problem-chain service, often cross-trade (connection_note REQUIRED); fringe = genuinely out-of-lane/peripheral.
            FRINGE: do NOT make fringe service pages. Put each out-of-lane item in `fringe_handoff` with a connection_note and, if it maps to a sibling brand/partner, a sibling_brand hint (e.g. mold → "Trusted Mold").

            RULES: every spoke needs a concise, geo-neutral head_keyword. granularity = "own_page" for all (maximal split; volume folds later). Audience and brand are SILOS, not flags. Do not invent services that contradict the exclusions.

            Respond with ONLY this JSON shape:
            {
              "silos": [
                {
                  "name": "Sump Pumps",
                  "head_keyword": "sump pump",
                  "page_type": "service",
                  "spokes": [
                    {"name": "Sump Pump Installation", "page_type": "service", "tag": "core", "head_keyword": "sump pump installation", "connection_note": null, "granularity": "own_page"}
                  ]
                }
              ],
              "fringe_handoff": [
                {"name": "Mold Remediation", "connection_note": "mold from chronic basement moisture", "sibling_brand": "Trusted Mold"}
              ]
            }
            PROMPT;
    }
}
