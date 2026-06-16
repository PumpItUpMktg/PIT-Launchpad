<?php

namespace App\Interview;

use App\Integrations\Claude\ClaudeClient;

/**
 * The headless owner-interview extractor: one strict-schema Anthropic call turns a
 * business description (+ optional connected-GBP signals) into a validated SiloSeed
 * and a VoiceProfile-shaped payload. Description-only is the floor — GBP is
 * grounding, never required. Output is validated against a closed schema; an invalid
 * or partial response is retried, and if it still fails the extractor throws rather
 * than emit a malformed seed (a fabricated trade is a lost-lead liability).
 *
 * The conversational multi-turn surface (a later PR) sits on top of this proven core;
 * here the "transcript" is a single description string.
 */
final class InterviewExtractor
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly SeedValidator $validator,
        private readonly int $maxAttempts = 2,
    ) {}

    /**
     * @param  list<string>|null  $gbpSignals  connected GBP categories/services, or null
     *
     * @throws SeedExtractionException when no attempt yields a valid payload
     */
    public function extract(string $description, ?array $gbpSignals = null): ExtractionResult
    {
        $system = $this->system();
        $prompt = $this->prompt($description, $gbpSignals);

        $errors = ['No model response.'];

        for ($attempt = 1; $attempt <= max(1, $this->maxAttempts); $attempt++) {
            $payload = $this->decode($this->claude->complete($prompt, $system));
            $errors = $this->validator->validate($payload);

            if ($errors === [] && is_array($payload)) {
                /** @var array<string, mixed> $seedData */
                $seedData = $payload['seed'];
                /** @var array<string, mixed> $voice */
                $voice = $payload['voice'];

                return new ExtractionResult(
                    SiloSeed::fromArray($seedData)->withGbpSignals($gbpSignals),
                    $voice,
                );
            }
        }

        throw new SeedExtractionException(
            'Could not extract a well-formed seed: '.implode(' ', $errors),
            $errors,
        );
    }

    /**
     * Extract the JSON object from a model response (tolerant of fences / prose).
     *
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
        return 'You are an onboarding analyst for a multi-tenant local-business marketing platform. '
            .'From a business owner\'s description you extract two things: a silo seed (trade, a FEW anchor '
            .'services — not exhaustive, the BROAD region they serve, explicit exclusions) and a voice profile '
            .'(tone, persona, audience). You reason about the customer\'s problem, not just the stated '
            .'service category. You return STRICT JSON only — never prose, never markdown fences.';
    }

    /**
     * @param  list<string>|null  $gbpSignals
     */
    private function prompt(string $description, ?array $gbpSignals): string
    {
        $gbp = $gbpSignals !== null && $gbpSignals !== []
            ? "\n\nThe owner has connected Google Business Profile. These listed categories/services are "
                ."ground truth for what they DO — use them to ground the trade + anchor services:\n- "
                .implode("\n- ", $gbpSignals)
            : "\n\nNo Google Business Profile is connected — rely on the description and your trade knowledge.";

        return "Business description:\n\"\"\"\n".trim($description)."\n\"\"\"".$gbp."\n\n"
            .'Respond with ONLY this JSON shape (no markdown):'."\n"
            .'{'."\n"
            .'  "seed": {'."\n"
            .'    "trade": "primary trade, lowercase (e.g. plumbing, roofing, hvac, electrical)",'."\n"
            .'    "anchor_services": ["a few core services they named — NOT an exhaustive list"],'."\n"
            .'    "region": "the BROAD region/area they serve as a short phrase (e.g. \"NJ, eastern PA\"), positioning only — NOT a town-by-town list; \"\" if not stated",'."\n"
            .'    "exclusions": ["work they explicitly will NOT do; [] if none stated"]'."\n"
            .'  },'."\n"
            .'  "voice": {'."\n"
            .'    "framing_model": "problem_solution",'."\n"
            .'    "tone_axes": {"formality": 0.0, "warmth": 0.0},'."\n"
            .'    "reading_level": "grade_8",'."\n"
            .'    "persona": {"perspective": "we", "identity": "...", "credibility": "..."},'."\n"
            .'    "language_rules": {"preferred": ["..."], "banned": ["..."]},'."\n"
            .'    "audience": {"primary": "..."},'."\n"
            .'    "cta_voice": "direct"'."\n"
            .'  }'."\n"
            .'}';
    }
}
