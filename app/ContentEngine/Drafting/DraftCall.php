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

        $json = substr($candidate, $start, $end - $start + 1);

        $decoded = json_decode($json, true);

        // A multi-KB HTML body wrapped in a JSON string is fragile: the model
        // routinely emits RAW control characters (literal newlines/tabs) inside a
        // string, which strict JSON forbids — a complete, end_turn response that
        // still won't decode. Repair only that (escape control chars that sit
        // inside a string) and retry. NOTE: an UNescaped quote inside the HTML is
        // not heuristically repairable — that needs the structured-output fix.
        if (! is_array($decoded)) {
            $decoded = json_decode(self::escapeControlCharsInStrings($json), true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Escape raw control characters (< 0x20) that occur INSIDE a JSON string,
     * leaving structural whitespace between tokens untouched. A single forward
     * scan tracking string state + backslash escaping; UTF-8 multibyte bytes
     * (>= 0x80) pass through unharmed.
     */
    private static function escapeControlCharsInStrings(string $json): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $len = strlen($json);

        for ($i = 0; $i < $len; $i++) {
            $c = $json[$i];

            if ($escaped) {
                $out .= $c;
                $escaped = false;

                continue;
            }

            if ($c === '\\') {
                $out .= $c;
                $escaped = true;

                continue;
            }

            if ($c === '"') {
                $out .= $c;
                $inString = ! $inString;

                continue;
            }

            if ($inString && ord($c) < 0x20) {
                $out .= match ($c) {
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    "\f" => '\\f',
                    "\x08" => '\\b',
                    default => sprintf('\\u%04x', ord($c)),
                };

                continue;
            }

            $out .= $c;
        }

        return $out;
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
