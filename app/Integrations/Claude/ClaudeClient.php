<?php

namespace App\Integrations\Claude;

/**
 * Thin seam for Claude text generation — the first use of the §2 adapter
 * pattern. Kept minimal and swappable: callers depend on this interface, the
 * concrete provider (Anthropic SDK) is bound in the container, and tests bind a
 * fake. The full provider/adapter layer lands in §2.
 */
interface ClaudeClient
{
    /**
     * Send a single prompt and return the model's text response.
     */
    public function complete(string $prompt, ?string $system = null): string;

    /**
     * Send a single prompt and return the text plus completion metadata
     * (stop_reason, token usage incl. the thinking slice) for diagnostics.
     */
    public function completeDetailed(string $prompt, ?string $system = null): CompletionResult;
}
