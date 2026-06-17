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
     * Silo expansion (Phase 2) — Opus, a generous token budget for the large
     * dimensional tree, and an assistant prefill of "{" so the model continues raw
     * JSON (no fences / preamble). Prefill is incompatible with extended thinking, so
     * thinking is null here; the tolerant parse + retry on the call site cover the rest.
     */
    public function expander(): AnthropicClaudeClient
    {
        return $this->make(
            (string) config('services.anthropic.model', 'claude-opus-4-8'),
            thinking: null,
            maxTokens: (int) config('services.anthropic.expander_max_tokens', 16000),
            prefill: '{',
        );
    }

    private function make(string $model, ?string $thinking, ?int $maxTokens = null, ?int $thinkingBudget = null, ?string $prefill = null): AnthropicClaudeClient
    {
        return new AnthropicClaudeClient(
            (string) config('services.anthropic.key'),
            $model,
            $maxTokens ?? (int) config('services.anthropic.max_tokens', 4096),
            thinking: $thinking,
            thinkingBudget: $thinkingBudget,
            prefill: $prefill,
        );
    }
}
