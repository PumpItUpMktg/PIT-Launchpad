<?php

namespace App\Integrations\Claude;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\Usage;

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
     * @param  string|null  $thinking  the extended-thinking type ('adaptive' |
     *                                 'enabled'), or null to send no thinking param.
     * @param  int|null  $thinkingBudget  when thinking is 'enabled', the explicit
     *                                    budget_tokens cap (must be ≥1024 and
     *                                    < maxTokens) so reasoning can't starve the
     *                                    output budget. Ignored for 'adaptive'/null.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-opus-4-8',
        private readonly int $maxTokens = 4096,
        private readonly ?string $thinking = 'adaptive',
        private readonly ?int $thinkingBudget = null,
    ) {}

    /**
     * The resolved call-site config (model, token budget, thinking mode) — for
     * diagnostics that need to report what client a path actually runs with.
     *
     * @return array{model: string, max_tokens: int, thinking: string|null, thinking_budget: int|null}
     */
    public function describe(): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'thinking' => $this->thinking,
            'thinking_budget' => $this->thinkingBudget,
        ];
    }

    public function complete(string $prompt, ?string $system = null): string
    {
        return $this->completeDetailed($prompt, $system)->text;
    }

    public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
    {
        $client = new Client(apiKey: $this->apiKey);

        $message = $client->messages->create(...$this->payload($prompt, $system));

        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        $usage = self::readUsage($message->usage);

        return new CompletionResult(
            text: $text,
            stopReason: $message->stopReason,
            inputTokens: $usage['input'],
            outputTokens: $usage['output'],
            thinkingTokens: $usage['thinking'],
        );
    }

    /**
     * Read token counts off the SDK Usage object WITHOUT tripping
     * "typed property must not be accessed before initialization". The Anthropic
     * PHP SDK declares `outputTokensDetails` (and its `thinkingTokens`) as typed
     * properties with no default; when the API response omits them, reading them
     * directly throws — and a null-safe `?->` does NOT help, because it still reads
     * the uninitialized property before checking for null. `??` uses isset-semantics
     * and returns the fallback instead of throwing. A non-fatal usage detail must
     * never crash a successful draft (this stranded SPG's pillar mid-generation).
     *
     * @return array{input: ?int, output: ?int, thinking: ?int}
     */
    public static function readUsage(Usage $usage): array
    {
        $details = $usage->outputTokensDetails ?? null;

        return [
            'input' => $usage->inputTokens ?? null,
            'output' => $usage->outputTokens ?? null,
            'thinking' => $details !== null ? ($details->thinkingTokens ?? null) : null,
        ];
    }

    /**
     * The named parameters for the Messages API call. The thinking param is
     * included only when this call site asked for it; an 'enabled' budget caps
     * reasoning below maxTokens so it can't exhaust the completion.
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
            $payload['thinking'] = $this->thinking === 'enabled' && $this->thinkingBudget !== null
                ? ['type' => 'enabled', 'budget_tokens' => $this->thinkingBudget]
                : ['type' => $this->thinking];
        }

        return $payload;
    }
}
