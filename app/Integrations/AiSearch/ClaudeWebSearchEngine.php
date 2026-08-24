<?php

namespace App\Integrations\AiSearch;

use App\Integrations\Claude\ClaudeClient;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * The Claude GEO engine — Anthropic's Messages API with the server-side `web_search` tool, so answers
 * reflect the live web and carry real citations (our shared text-only {@see ClaudeClient}
 * can't do web search, so this talks to the API directly over HTTP — version-stable JSON, faked in tests).
 * Disabled (no-op) without an API key. Errors resolve to null so a run records "not measured" rather than a
 * fabricated blank.
 */
class ClaudeWebSearchEngine implements AiEngineProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $maxUses = 5,
        private readonly int $maxTokens = 1024,
        private readonly int $timeout = 60,
        private readonly string $version = '2023-06-01',
    ) {}

    public function key(): string
    {
        return 'claude';
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
                ->withHeaders(['x-api-key' => (string) $this->apiKey, 'anthropic-version' => $this->version])
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => $this->maxTokens,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => $this->maxUses]],
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->parse((array) $response->json('content', []));
    }

    /**
     * Flatten the response content blocks into answer text + deduped citations. Citations come from two
     * places: inline `citations` on text blocks, and `web_search_tool_result` result blocks. Shape-tolerant
     * (best-effort key reads) so a minor API-shape change degrades gracefully rather than throwing.
     *
     * @param  list<mixed>  $blocks
     */
    private function parse(array $blocks): AiAnswer
    {
        $text = '';
        $citations = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');

            if ($type === 'text') {
                $text .= (string) ($block['text'] ?? '');
                foreach ((array) ($block['citations'] ?? []) as $c) {
                    $this->addCitation($citations, $c);
                }
            } elseif ($type === 'web_search_tool_result') {
                foreach ((array) ($block['content'] ?? []) as $c) {
                    $this->addCitation($citations, $c);
                }
            }
        }

        return new AiAnswer(trim($text), array_values($citations));
    }

    /**
     * @param  array<string, array{url: string, title: string}>  $citations  keyed by url for dedup
     * @param  mixed  $raw
     */
    private function addCitation(array &$citations, $raw): void
    {
        if (! is_array($raw)) {
            return;
        }
        $url = trim((string) ($raw['url'] ?? ''));
        if ($url === '') {
            return;
        }
        $citations[$url] ??= ['url' => $url, 'title' => trim((string) ($raw['title'] ?? ''))];
    }
}
