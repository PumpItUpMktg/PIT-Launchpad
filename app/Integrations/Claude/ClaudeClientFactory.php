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
    /**
     * Drafting (§6b) — quality-sensitive, Sonnet. Extended thinking is ENABLED
     * with an explicit budget kept below a materially larger max_tokens, so a
     * long reasoning roll can't exhaust the completion before any text (the
     * empty-body / stop_reason=max_tokens bug under adaptive thinking + 4096).
     */
    public function drafting(): AnthropicClaudeClient
    {
        return $this->make(
            (string) config('services.anthropic.drafting_model', 'claude-sonnet-4-6'),
            thinking: 'enabled',
            maxTokens: (int) config('services.anthropic.drafting_max_tokens', 12000),
            thinkingBudget: (int) config('services.anthropic.drafting_thinking_budget', 4000),
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

    /**
     * Silo expansion (Phase 2) — Opus with adaptive thinking and a generous token
     * budget for the large dimensional tree. No assistant prefill: this model rejects
     * it (400 "must end with a user message"); the call site's tolerant parse
     * (fence-strip + outermost {...}) + retry handle raw-JSON divergence instead.
     */
    public function expander(): AnthropicClaudeClient
    {
        return $this->make(
            (string) config('services.anthropic.model', 'claude-opus-4-8'),
            thinking: 'adaptive',
            maxTokens: (int) config('services.anthropic.expander_max_tokens', 16000),
        );
    }

    private function make(string $model, ?string $thinking, ?int $maxTokens = null, ?int $thinkingBudget = null): AnthropicClaudeClient
    {
        return new AnthropicClaudeClient(
            (string) config('services.anthropic.key'),
            $model,
            $maxTokens ?? (int) config('services.anthropic.max_tokens', 4096),
            thinking: $thinking,
            thinkingBudget: $thinkingBudget,
            timeout: (int) config('services.anthropic.timeout', 240),
            maxRetries: (int) config('services.anthropic.max_retries', 1),
        );
    }
}
