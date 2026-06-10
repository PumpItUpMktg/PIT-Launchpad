<?php

namespace App\Integrations\Claude;

/**
 * The single definition of each Claude call-site client. Both the container
 * bindings and the drafter probe build their clients here, so the model +
 * thinking + token budget a call site runs with are defined in exactly one
 * place. This is what makes the drafter probe faithful: it exercises the *same*
 * client the live Drafter gets, not a probe-only Haiku stand-in — closing the
 * probe-vs-real-path divergence (the Claude vendor probe passing while the
 * Drafter fails) at its root.
 */
class ClaudeClientFactory
{
    /** Drafting (§6b) — quality-sensitive, Sonnet with adaptive thinking. */
    public function drafting(): AnthropicClaudeClient
    {
        return $this->make(
            (string) config('services.anthropic.drafting_model', 'claude-sonnet-4-6'),
            thinking: 'adaptive',
        );
    }

    /** Relevance scoring (§6a) — cheap Haiku, no extended thinking. */
    public function scoring(): AnthropicClaudeClient
    {
        return $this->make(
            (string) config('services.anthropic.scoring_model', 'claude-haiku-4-5'),
            thinking: null,
        );
    }

    /** The default seam client — Opus with adaptive thinking. */
    public function default(): AnthropicClaudeClient
    {
        return $this->make(
            (string) config('services.anthropic.model', 'claude-opus-4-8'),
            thinking: 'adaptive',
        );
    }

    private function make(string $model, ?string $thinking): AnthropicClaudeClient
    {
        return new AnthropicClaudeClient(
            (string) config('services.anthropic.key'),
            $model,
            (int) config('services.anthropic.max_tokens', 4096),
            thinking: $thinking,
        );
    }
}
