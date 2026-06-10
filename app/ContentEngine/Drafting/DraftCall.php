<?php

namespace App\ContentEngine\Drafting;

use App\Integrations\Claude\ClaudeClient;

/**
 * The single hardened drafting MECHANISM, shared by every draft kind (post,
 * page, …): one model call through the budget-fixed drafting client, then the
 * fence/truncation-tolerant JSON extraction, returning a DraftAttempt (raw text
 * + parsed payload + completion metadata). This is the layer where the recent
 * bugs lived — the empty/truncated-budget exhaustion and the fenced-output parse
 * — so it is fixed ONCE here and neither kind reimplements an inch of it. The
 * per-kind prompt + grounding live in the sibling drafters above this line.
 *
 * The ClaudeClient call is intentionally NOT caught here — a transport/HTTP
 * failure propagates so the guard can record its cause; this only fails to parse
 * (yielding an empty payload), never silently swallows.
 */
final class DraftCall
{
    public function __construct(
        private readonly ClaudeClient $claude,
    ) {}

    public function attempt(string $system, string $prompt): DraftAttempt
    {
        $result = $this->claude->completeDetailed($prompt, $system);

        return new DraftAttempt($result->text, DraftPayload::fromArray(self::parse($result->text)), $result);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parse(string $response): array
    {
        $candidate = self::unfence($response);

        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($candidate, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The model often wraps its JSON in a markdown code fence (```json … ```),
     * sometimes prefaced with prose. Return the first fenced block's contents when
     * present, so the brace-span extraction can't be poisoned by a stray brace in
     * that prose (e.g. "using a {key: value} shape:"). No fence → the response is
     * returned unchanged and the brace span handles it (incl. truncated output).
     */
    private static function unfence(string $response): string
    {
        if (preg_match('/```(?:json)?\s*\n?(.+?)\n?```/is', $response, $m) === 1) {
            return trim($m[1]);
        }

        return $response;
    }
}
