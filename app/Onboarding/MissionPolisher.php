<?php

namespace App\Onboarding;

use App\Integrations\Claude\ClaudeClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polishes a client's raw mission wording into a crisp one-or-two-sentence statement — an opt-in
 * CLEANUP, never authorship. The prompt is tightly constrained to preserve the owner's meaning and
 * voice: grammar/clarity/tightening only, NO new facts, services, credentials, numbers, guarantees,
 * or superlatives (an invented claim on a client's site is a false representation — the same honesty
 * rule every composer follows).
 *
 * Fail-open by design: any API failure or degenerate output returns null and the caller stores the
 * client's wording verbatim — enhancement must never block intake or lose what the client typed.
 * Runs on the cheap scoring model (one small completion; see the AppServiceProvider binding).
 */
final class MissionPolisher
{
    /** A mission renders as ONE standout statement — anything longer than this is a failed polish. */
    private const MAX_LENGTH = 300;

    private const SYSTEM = 'You polish a small business\'s mission statement. Tighten the owner\'s own '
        .'wording: fix grammar, spelling, and clarity, and compress it to one or two short, confident '
        .'sentences. PRESERVE the owner\'s meaning and voice. NEVER add facts, services, credentials, '
        .'numbers, guarantees, or superlatives that are not in the original text. '
        .'Return ONLY the polished statement — no quotes, no markdown, no commentary.';

    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * The polished statement, or null when enhancement failed or produced something unusable —
     * the caller then falls back to the client's verbatim wording.
     */
    public function polish(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            $text = $this->claude->complete(
                "Polish this mission statement:\n\n".$raw,
                self::SYSTEM,
            );
        } catch (Throwable $e) {
            Log::warning('Mission polish failed — falling back to the client\'s verbatim wording.', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->cleanOutput($text);
    }

    /**
     * Normalize the model output to a bare statement — strip code fences and wrapping quotes, collapse
     * whitespace, and reject empty or overlong results (a failed polish, not a mission).
     */
    private function cleanOutput(string $text): ?string
    {
        $text = trim($text);

        // Strip a markdown fence if the model wrapped the statement anyway.
        if (preg_match('/^```[a-z]*\s*(.*?)\s*```$/s', $text, $m) === 1) {
            $text = $m[1];
        }

        // Collapse to a single line — a mission is one statement, not paragraphs.
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        // Strip wrapping quotes ("..." / '...' / “...”).
        $text = trim($text, "\"'");
        if (str_starts_with($text, '“') && str_ends_with($text, '”')) {
            $text = trim(mb_substr($text, 1, -1));
        }

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return null;
        }

        return $text;
    }
}
