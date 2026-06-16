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
            .'Through natural conversation you cover these ESSENTIALS, tracking which are still missing as you go: '
            .'(1) trade, (2) a few core/anchor services — not an exhaustive list, (3) the BROAD region they serve '
            .'(state/region, NOT a town-by-town list — specific service areas are managed separately), '
            .'(4) any work they will NOT do (exclusions), and (5) voice material — positioning, who they serve, and tone. '
            .'Open broad, then ask ONE focused follow-up at a time, building on what they said. If an essential is still '
            .'uncovered, gently re-ask for it before finishing — e.g. no region yet → "What region do you serve?"; no '
            .'exclusions yet → "Anything you specifically don\'t do?". Set "ready" to true ONLY once the essentials are '
            .'covered OR the owner clearly signals they are done — then give a brief, friendly closing message. '
            .'Never invent answers on the owner\'s behalf; if they decline or don\'t know, move on. '
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
