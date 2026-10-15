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
 * retried once whenever a second full request still fits the drafting job's
 * budget: elapsed + the pause + one more per-request timeout + a margin for the
 * work around the call must stay inside it. With the shipped numbers (240s
 * request, 600s job) that is every drop — a reset in the first second and a cut
 * two minutes into a long generation alike. Only when the budget is tighter than
 * two requests (a shorter job, a longer request timeout) does a late drop surface
 * without a retry. Sump Pump Gurus lost nine section drafts and two post drafts
 * to single blips on one degraded afternoon — each a Sonnet call and a manual
 * retry — and a 60-second retry window still let a mid-generation cut through.
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

    /** The pause before the one retry — long enough for a reset to clear, short enough to be free. */
    public const RETRY_DELAY_SECONDS = 3;

    /** Seconds kept free for the work around the model call (grounding, parse, write, push) when sizing the retry. */
    public const BUDGET_MARGIN_SECONDS = 60;

    /** The per-request HTTP timeout the retry has to fit (services.anthropic.timeout). */
    private readonly int $requestTimeout;

    /** The drafting job's budget — what every drafting job's $timeout is held at (launchpad.drafting.job_budget_seconds). */
    private readonly int $jobBudget;

    /** @var Closure(): float  monotonic seconds — injectable so a test can make a failure "slow" */
    private readonly Closure $clock;

    /** @var Closure(int): void  the pause — injectable so a test does not sleep */
    private readonly Closure $pause;

    public function __construct(
        private readonly ClaudeClient $claude,
        ?Closure $clock = null,
        ?Closure $pause = null,
        ?int $requestTimeout = null,
        ?int $jobBudget = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->pause = $pause ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->requestTimeout = $requestTimeout ?? (int) config('services.anthropic.timeout', 240);
        $this->jobBudget = $jobBudget ?? (int) config('launchpad.drafting.job_budget_seconds', 600);
    }

    public function attempt(string $system, string $prompt): DraftAttempt
    {
        $started = ($this->clock)();
        try {
            $result = $this->claude->completeDetailed($prompt, $system);
        } catch (APIConnectionException $e) {
            $elapsed = ($this->clock)() - $started;
            $cause = DraftFailure::causeChain($e);
            if (! $this->retryFits($elapsed)) {
                Log::warning('Draft call: connection dropped — no retry, a second request would not fit the job', [
                    'after_seconds' => round($elapsed, 1), 'request_timeout' => $this->requestTimeout, 'job_budget' => $this->jobBudget, 'cause' => $cause,
                ]);

                throw $e;
            }
            Log::warning('Draft call: connection dropped — retrying once', ['after_seconds' => round($elapsed, 1), 'cause' => $cause]);
            ($this->pause)(self::RETRY_DELAY_SECONDS);
            try {
                $result = $this->claude->completeDetailed($prompt, $system);
            } catch (APIConnectionException $again) {
                Log::warning('Draft call: connection dropped again on the retry — giving up', [
                    'after_seconds' => round(($this->clock)() - $started, 1), 'cause' => DraftFailure::causeChain($again),
                ]);

                throw $again;
            }
        }

        return new DraftAttempt($result->text, DraftPayload::fromArray(self::parse($result->text)), $result);
    }

    /** Whether the pause plus one more full request, with the margin, still fits the job's budget. */
    public function retryFits(float $elapsed): bool
    {
        return $elapsed + self::RETRY_DELAY_SECONDS + $this->requestTimeout + self::BUDGET_MARGIN_SECONDS <= $this->jobBudget;
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
