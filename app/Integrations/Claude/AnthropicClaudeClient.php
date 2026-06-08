<?php

namespace App\Integrations\Claude;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;

/**
 * Anthropic-backed ClaudeClient using the official PHP SDK. Defaults to
 * claude-opus-4-8 with adaptive thinking; the model is configurable via
 * config/services.php so operators can choose a cheaper tier when appropriate.
 */
class AnthropicClaudeClient implements ClaudeClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-opus-4-8',
        private readonly int $maxTokens = 4096,
    ) {}

    public function complete(string $prompt, ?string $system = null): string
    {
        $client = new Client(apiKey: $this->apiKey);

        $message = $client->messages->create(
            maxTokens: $this->maxTokens,
            messages: [['role' => 'user', 'content' => $prompt]],
            model: $this->model,
            system: $system,
            thinking: ['type' => 'adaptive'],
        );

        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return $text;
    }
}
