<?php

namespace App\Interview\Conversation;

use App\Integrations\Claude\ClaudeClient;

/**
 * The conversational intelligence over the proven single-shot extractor: given the
 * transcript so far it produces the next interviewer message and decides when enough
 * has been gathered (trade, core services, service area, exclusions, and voice /
 * positioning / tone) to run extraction. One Claude call per turn through the shared
 * seam, so it is fully mockable. GBP signals, when connected, inform the questions
 * but never replace confirmation.
 */
final class Interviewer
{
    public function __construct(
        private readonly ClaudeClient $claude,
    ) {}

    /**
     * @param  list<Turn>  $transcript
     * @param  list<string>|null  $gbpSignals
     */
    public function next(array $transcript, ?array $gbpSignals = null): InterviewReply
    {
        $decoded = $this->decode($this->claude->complete($this->prompt($transcript, $gbpSignals), $this->system()));

        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message === '') {
            $message = $transcript === []
                ? 'Hi! Tell me about your business — what do you do, and who do you do it for?'
                : 'Got it — anything else you want prospective customers to know?';
        }

        return new InterviewReply($message, (bool) ($decoded['ready'] ?? false));
    }

    private function system(): string
    {
        return 'You are a warm, efficient onboarding interviewer for a local-service business marketing platform. '
            .'Through natural conversation you learn the owner\'s trade, their core services (a few — not an exhaustive '
            .'list), their service area, any work they will NOT do, and their voice: positioning, who they serve, and tone. '
            .'Ask ONE focused question at a time and build on what they said. When you have enough across all of those areas '
            .'(or the owner signals they are done), set "ready" to true and give a brief, friendly closing message. '
            .'Respond with STRICT JSON only — {"message": "...", "ready": false} — never prose, never markdown.';
    }

    /**
     * @param  list<Turn>  $transcript
     * @param  list<string>|null  $gbpSignals
     */
    private function prompt(array $transcript, ?array $gbpSignals): string
    {
        if ($transcript === []) {
            $gbp = $gbpSignals !== null && $gbpSignals !== []
                ? ' Their connected Google Business Profile lists: '.implode(', ', $gbpSignals)
                .'. Use that to inform your questions, but still confirm it in their words.'
                : '';

            return 'Begin the interview. Open with a warm, single question that invites the owner to describe their '
                .'business in their own words.'.$gbp."\n\nRespond with JSON {\"message\": \"...\", \"ready\": false}.";
        }

        $lines = [];
        foreach ($transcript as $turn) {
            $lines[] = ($turn->isOwner() ? 'Owner' : 'Interviewer').': '.$turn->text;
        }

        $gbp = $gbpSignals !== null && $gbpSignals !== []
            ? "\n\nConnected GBP services (ground truth for what they do): ".implode(', ', $gbpSignals)
            : '';

        return "Conversation so far:\n".implode("\n", $lines).$gbp."\n\n"
            .'Ask the next single question, OR if you now have enough (trade, a few core services, service area, '
            .'exclusions, and a feel for their voice/positioning/tone) set "ready" to true with a brief closing message. '
            .'Respond with JSON {"message": "...", "ready": false|true}.';
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
