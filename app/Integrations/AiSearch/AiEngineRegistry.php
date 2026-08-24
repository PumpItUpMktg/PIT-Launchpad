<?php

namespace App\Integrations\AiSearch;

/**
 * The set of AI search engines GEO measures against — the multi-engine seam. Engines register here
 * (Claude web-search, Perplexity Sonar, later OpenAI/Gemini); the audit fans a prompt out across every
 * ENABLED engine and stamps each reading with the engine key. Keyed by `key()`, so registering the same
 * engine twice just replaces it.
 */
class AiEngineRegistry
{
    /** @var array<string, AiEngineProvider> */
    private array $engines = [];

    public function register(AiEngineProvider $engine): static
    {
        $this->engines[$engine->key()] = $engine;

        return $this;
    }

    /** Every registered engine, configured or not (for the doctor's inventory). @return list<AiEngineProvider> */
    public function all(): array
    {
        return array_values($this->engines);
    }

    /** Only the engines that are actually configured — the ones the audit will call. @return list<AiEngineProvider> */
    public function enabled(): array
    {
        return array_values(array_filter($this->engines, fn (AiEngineProvider $e): bool => $e->enabled()));
    }

    public function get(string $key): ?AiEngineProvider
    {
        return $this->engines[$key] ?? null;
    }
}
