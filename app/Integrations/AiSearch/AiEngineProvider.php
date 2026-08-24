<?php

namespace App\Integrations\AiSearch;

/**
 * A GEO (Generative Engine Optimization) answer source — one AI search engine, queried with a prompt and
 * returning a normalized {@see AiAnswer}. Mock-first behind the seam: `enabled()` is false until the engine
 * is configured, so the audit no-ops rather than fabricating visibility. Phase 1 ships the Claude
 * web-search adapter; Perplexity/OpenAI/Gemini bind later under the same contract.
 */
interface AiEngineProvider
{
    /** Stable engine identifier — stored on each snapshot (e.g. 'claude'). */
    public function key(): string;

    public function enabled(): bool;

    /** Ask the engine a question; null on an API error or when the engine isn't configured. */
    public function ask(string $prompt): ?AiAnswer;
}
