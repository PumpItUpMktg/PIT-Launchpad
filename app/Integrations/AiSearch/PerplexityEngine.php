<?php

namespace App\Integrations\AiSearch;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * The Perplexity (Sonar) GEO engine — its chat-completions API answers with **real-time citations
 * natively**, which makes it the closest API proxy for how an AI search product actually cites sources.
 * One HTTP call → answer text + citations (from `search_results` and/or the legacy `citations` list).
 * Disabled (no-op) without an API key; errors resolve to null so a run records "not measured" rather than
 * a fabricated blank.
 */
class PerplexityEngine implements AiEngineProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 60,
    ) {}

    public function key(): string
    {
        return 'perplexity';
    }

    public function enabled(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    public function ask(string $prompt): ?AiAnswer
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = $this->http
                ->withToken((string) $this->apiKey)
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $this->model,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $text = trim((string) ($response->json('choices.0.message.content') ?? ''));
        if ($text === '') {
            return null;
        }

        $citations = [];
        foreach ((array) $response->json('search_results', []) as $result) {
            if (is_array($result)) {
                $this->addCitation($citations, $result);
            }
        }
        foreach ((array) $response->json('citations', []) as $url) {
            if (is_string($url)) {
                $this->addCitation($citations, ['url' => $url, 'title' => '']);
            }
        }

        return new AiAnswer($text, array_values($citations));
    }

    /**
     * @param  array<string, array{url: string, title: string}>  $citations  keyed by url for dedup
     * @param  array<string, mixed>  $raw
     */
    private function addCitation(array &$citations, array $raw): void
    {
        $url = trim((string) ($raw['url'] ?? ''));
        if ($url === '') {
            return;
        }
        $citations[$url] ??= ['url' => $url, 'title' => trim((string) ($raw['title'] ?? ''))];
    }
}
