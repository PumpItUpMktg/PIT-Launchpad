<?php

namespace App\ContentEngine\Drafting;

use Anthropic\Core\Exceptions\APIConnectionException;
use App\Integrations\Claude\ClaudeClient;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * The single hardened drafting MECHANISM, shared by every draft kind (post,
 * page, …): one model call through the budget-fixed drafting client, then the
 * sentinel-block extraction, returning a DraftAttempt (raw text + parsed payload +
 * completion metadata). The wire format is sentinel-delimited blocks (see
 * {@see Sentinel}), NOT JSON — the model emits raw content between markers, so the
 * unescaped-quote / raw-control-char failures that the old JSON encoding suffered
 * cannot occur. This is the one place the format is decoded; neither kind
 * reimplements an inch of it. The per-kind prompt + grounding live in the sibling
 * drafters above this line.
 *
 * The ClaudeClient call is intentionally NOT caught here — a transport/HTTP
 * failure propagates so the guard can record its cause; this only fails to parse
 * (yielding an empty payload), never silently swallows. ONE exception to that: a
 * DROPPED CONNECTION (the SDK's APIConnectionException with no HTTP response — the
 * SDK itself retries only HTTP 408/409/429/5xx, never a transport failure) is
 * retried once, and only when the first attempt failed FAST. A fast failure is a
 * reset or a refused connection, a blip worth one more try; a slow one is the
 * per-request timeout, and a second full request would not fit the job's budget.
 * Sump Pump Gurus lost nine section drafts and two post drafts to single blips on
 * one degraded afternoon — each a Sonnet call and a manual retry.
 */
final class DraftCall
{
    /**
     * The shared proof-handling rule both kinds obey — single-sourced in the
     * drafting core so it is not duplicated (or drift) per drafter. A grounding
     * fact (claim/proof, offer, market, review) is a FACT to express in the
     * brand's own prose, never an entity's raw text to splice in verbatim, and
     * never a marker. (The post drafter rendered <sup>[review]</sup> tokens; the
     * page drafter spliced faker offer terms verbatim into FAQ copy — page 196.)
     */
    public const PROOF_RULES =
        'How to use grounding facts: a claim / proof / offer / market / review is a FACT to express in your own '
        .'natural prose — NEVER splice an entity\'s raw text in verbatim, and NEVER emit a placeholder, citation, '
        .'or annotation token (no <sup>…</sup>, no [review]/[warranty]/[citation]-style brackets, no footnote '
        .'markers). Record any business claim you used in the separate `claim` blocks (text + its id) — that is the '
        .'ONLY place an id appears, NEVER inline. If a fact cannot be written as a clean, natural sentence, omit that '
        .'sentence entirely rather than padding it.';

    /** A connection failure inside this many seconds is a blip, not a timeout — retried once. */
    public const FAST_FAILURE_SECONDS = 60;

    /** The pause before the one retry — long enough for a reset to clear, short enough to be free. */
    public const RETRY_DELAY_SECONDS = 3;

    /** @var Closure(): float  monotonic seconds — injectable so a test can make a failure "slow" */
    private readonly Closure $clock;

    /** @var Closure(int): void  the pause — injectable so a test does not sleep */
    private readonly Closure $pause;

    public function __construct(
        private readonly ClaudeClient $claude,
        ?Closure $clock = null,
        ?Closure $pause = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->pause = $pause ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function attempt(string $system, string $prompt): DraftAttempt
    {
        $started = ($this->clock)();
        try {
            $result = $this->claude->completeDetailed($prompt, $system);
        } catch (APIConnectionException $e) {
            $elapsed = ($this->clock)() - $started;
            if ($elapsed > self::FAST_FAILURE_SECONDS) {
                throw $e; // the request ran to its timeout — another one will not fit the job
            }
            Log::warning('Draft call: connection dropped — retrying once', ['after_seconds' => round($elapsed, 1), 'error' => $e->getMessage()]);
            ($this->pause)(self::RETRY_DELAY_SECONDS);
            $result = $this->claude->completeDetailed($prompt, $system);
        }

        return new DraftAttempt($result->text, DraftPayload::fromArray(self::parse($result->text)), $result);
    }

    /**
     * Decode the sentinel wire format into the DraftPayload array shape. Kept as
     * the public static entry point (used by attempt() and the parser tests); the
     * extraction itself lives in {@see SentinelParser}.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $response): array
    {
        return SentinelParser::parse($response);
    }
}
