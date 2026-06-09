<?php

namespace App\Integrations\Claude;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;

/**
 * Anthropic-backed ClaudeClient using the official PHP SDK. The model and the
 * extended-thinking mode are per-call-site settings (declared at the binding),
 * not hardcoded: drafting and vision want adaptive thinking, while the cheap
 * scoring pass declares none — Haiku doesn't support extended thinking, and a
 * relevance score doesn't want a reasoning budget anyway. Call sites declare
 * intent; there is no model-name sniffing.
 */
class AnthropicClaudeClient implements ClaudeClient
{
    /**
     * @param  string|null  $thinking  the extended-thinking type (e.g. 'adaptive'),
     *                                 or null to send no thinking param at all.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-opus-4-8',
        private readonly int $maxTokens = 4096,
        private readonly ?string $thinking = 'adaptive',
    ) {}

    public function complete(string $prompt, ?string $system = null): string
    {
        $client = new Client(apiKey: $this->apiKey);

        $message = $client->messages->create(...$this->payload($prompt, $system));

        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /**
     * The named parameters for the Messages API call. The thinking param is
     * included only when this call site asked for it.
     *
     * @return array<string, mixed>
     */
    public function payload(string $prompt, ?string $system = null): array
    {
        $payload = [
            'maxTokens' => $this->maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'model' => $this->model,
            'system' => $system,
        ];

        if ($this->thinking !== null) {
            $payload['thinking'] = ['type' => $this->thinking];
        }

        return $payload;
    }
}
