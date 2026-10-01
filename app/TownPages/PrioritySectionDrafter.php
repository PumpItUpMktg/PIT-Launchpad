<?php

namespace App\TownPages;

use App\ContentEngine\Drafting\DraftAttempt;
use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\ContentEngine\Drafting\Sentinel;
use App\ContentEngine\Drafting\SentinelContract;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Service;
use App\Publishing\Blocks\LocationSubject;

/**
 * Drafts a town page's PRIORITY SECTIONS — one short H2 section + two FAQ items per priority keyword —
 * in ONE model call through the shared {@see DraftCall}. Grounded like the page itself
 * ({@see PageGroundingAssembler}: brand, voice, the town's own Census / FEMA / elevation / soil facts)
 * plus the keyword's service; the prompt forbids every other town, any price, and any claim not given,
 * and asks for the town's own facts to lead so two towns never read alike.
 *
 * Wire format: sentinel blocks keyed `section.{keyword_id}.heading`, `section.{keyword_id}.body` and
 * `faq.{keyword_id}` (question || answer, twice). {@see parse()} turns an attempt into the writer's shape.
 */
final class PrioritySectionDrafter
{
    public function __construct(
        private readonly DraftCall $call,
        private readonly PageGroundingAssembler $grounding,
        private readonly LocationSubject $subject,
        private readonly PriorityKeywords $priority,
    ) {}

    /** @param  list<Keyword>  $keywords */
    public function attempt(Content $page, array $keywords): DraftAttempt
    {
        return $this->call->attempt($this->system(), $this->prompt($page, $keywords));
    }

    /**
     * The drafted sections an attempt produced, one per keyword that came back whole (heading + body);
     * FAQ items ride with their keyword. Keywords the model skipped are simply absent.
     *
     * @param  list<Keyword>  $keywords
     * @return list<array{keyword_id: string, keyword: string, service_id: string|null, heading: string, body: string, faqs: list<array{question: string, answer: string}>}>
     */
    public function parse(DraftAttempt $attempt, array $keywords): array
    {
        $slots = $attempt->payload->slots ?? [];
        $out = [];
        foreach ($keywords as $keyword) {
            $id = (string) $keyword->id;
            $heading = trim(self::first($slots["section.{$id}.heading"] ?? null));
            $body = trim(self::first($slots["section.{$id}.body"] ?? null));
            if ($heading === '' || $body === '') {
                continue;
            }
            $faqs = [];
            foreach (self::items($slots["faq.{$id}"] ?? null) as $raw) {
                $parts = array_map('trim', explode(Sentinel::FIELD, (string) $raw, 2));
                if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                    $faqs[] = ['question' => rtrim($parts[0], '?').'?', 'answer' => $parts[1]];
                }
            }
            $out[] = [
                'keyword_id' => $id,
                'keyword' => (string) $keyword->query,
                'service_id' => $this->priority->serviceFor($keyword)?->id,
                'heading' => $heading,
                'body' => $body,
                'faqs' => array_slice($faqs, 0, 2),
            ];
        }

        return $out;
    }

    /** A slot value as the payload carries it — a lone item collapses to a string, repeats stay a list. */
    private static function first(mixed $value): string
    {
        return self::items($value)[0] ?? '';
    }

    /** @return list<string> */
    private static function items(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(fn ($v): string => is_string($v) ? $v : (is_scalar($v) ? (string) $v : ''), $value));
    }

    private function system(): string
    {
        return 'You are a home-services website page builder writing SHORT, specific sections for ONE town\'s page. '
            .'You write in the brand voice provided. You may state a fact about the town ONLY if it is in TOWN FACTS, '
            .'and a fact about the business ONLY if it is in BRAND or SERVICE. Never name any other town, '
            .'neighborhood, county seat, or region. Never give a price, a cost range, a guarantee, a warranty, '
            .'a licence, a year founded, or a number of jobs. No phone numbers, no emoji, no exclamation marks.';
    }

    /** @param  list<Keyword>  $keywords */
    private function prompt(Content $page, array $keywords): string
    {
        $grounding = $this->grounding->assemble($page);
        ['label' => $town, 'state' => $state] = $this->subject->resolve($page);
        $place = trim($town.($state !== '' ? ', '.$state : ''));

        $parts = [];
        $parts[] = "TOWN (this page's ONE subject — name it as written): {$place}";
        $facts = is_array($grounding->location['local_facts'] ?? null) ? $grounding->location['local_facts'] : [];
        $parts[] = $facts === []
            ? 'TOWN FACTS: none on record — write about the service in this town without inventing any local detail.'
            : "TOWN FACTS (the only local facts you may use; let ONE lead each section so this town reads unlike any other):\n".$this->json($facts);
        $parts[] = $grounding->voiceProfile === []
            ? 'VOICE: default brand voice (warm, plain, confident; first-person plural "we").'
            : "VOICE PROFILE (write in this voice):\n".$this->json($grounding->voiceProfile);
        $parts[] = "BRAND (use exactly; never invent):\n".$this->json($grounding->branding);

        $blocks = [];
        foreach ($keywords as $keyword) {
            $id = (string) $keyword->id;
            $service = $this->priority->serviceFor($keyword);
            $parts[] = "KEYWORD {$id}: \"{$keyword->query}\"".($service instanceof Service
                ? "\nSERVICE for this keyword: ".$this->json(array_filter([
                    'name' => trim((string) $service->name),
                    'description' => trim((string) $service->description),
                ]))
                : '');
            $blocks[] = "<<<SLOT:section.{$id}.heading>>>\n…a heading of 4–9 words that contains \"{$keyword->query}\" verbatim AND \"{$town}\" (e.g. \"{$keyword->query} in {$town}\" or a natural variant)…\n<<<END>>>\n"
                ."<<<SLOT:section.{$id}.body>>>\n…ONE paragraph, 2–3 sentences, 50–90 words, plain prose (no HTML): what this service means for homes in {$town}, leading with one TOWN FACT where given, and why to call us for it…\n<<<END>>>\n"
                ."<<<SLOT:faq.{$id}>>>\nquestion naming {$town} and the keyword or a plain variant of it (e.g. \"Do you handle {$keyword->query} in {$town}?\") || answer of 1–3 sentences\n<<<END>>>\n"
                ."<<<SLOT:faq.{$id}>>>\na second, DIFFERENT question for the same keyword in {$town} (an advisory one: when, why, what to expect) || answer of 1–3 sentences\n<<<END>>>";
        }
        $parts[] = 'RULES: every section and every FAQ answer must be specific to '.$place.' and must not be reusable on another '
            .'town\'s page by swapping the name. Do not repeat a TOWN FACT across sections. Do not list other services. '
            .'Do not open two sections the same way. Keep the keyword phrase natural — once in the heading, at most once in the body.';
        $parts[] = SentinelContract::describe(
            'Return ONLY sentinel-delimited blocks — no JSON, no prose, no code fences. Write each value RAW between the markers. The blocks, in this order:',
            implode("\n", $blocks),
        );

        return implode("\n\n", $parts);
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
