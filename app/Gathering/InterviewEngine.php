<?php

namespace App\Gathering;

use App\Enums\InterviewSection;
use App\Enums\InterviewStatus;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Interview;
use App\Models\InterviewTurn;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

/**
 * The adaptive owner-interview engine (gathering relay). Operator-led: the operator conducts the
 * call and types the owner's answers; this engine produces each next question. Section GOALS, not
 * a script — the system prompt loads everything already known (imported identity, locations,
 * services) so the model references it ("You've got locations in Trooper and Montclair — do they
 * cover different areas?") and never re-asks what import answered. Each assistant turn is tagged
 * with the section it probes and carries a coverage self-assessment that drives the operator's
 * meter. Bound to the Sonnet-class drafting lane; tests bind a fake — no network.
 */
class InterviewEngine
{
    public function __construct(private readonly ClaudeClient $claude) {}

    /** Start (or resume) — returns the site's open interview, creating one with its opener. */
    public function start(Site $site): Interview
    {
        $open = Interview::query()
            ->where('site_id', $site->id)
            ->where('status', InterviewStatus::InProgress)
            ->latest('started_at')
            ->first();

        if ($open !== null) {
            return $open;
        }

        $interview = Interview::query()->create([
            'site_id' => $site->id,
            'status' => InterviewStatus::InProgress,
            'started_at' => now(),
        ]);

        $this->ask($interview);

        return $interview;
    }

    /** Record the operator-typed owner answer, then produce the next adaptive question. */
    public function answer(Interview $interview, string $content): InterviewTurn
    {
        $interview->turns()->create(['role' => 'operator', 'content' => trim($content)]);

        return $this->ask($interview);
    }

    /** Operator skip — the engine moves on and won't circle back to this section. */
    public function skipSection(Interview $interview, InterviewSection $section): InterviewTurn
    {
        $interview->turns()->create([
            'role' => 'operator',
            'content' => "[Operator skipped the {$section->label()} section — do not ask about it again.]",
            'section_tag' => $section->value,
        ]);

        // Skipped = satisfied for the meter, STICKY across later model self-assessments
        // (the `_skipped` list survives every coverage refresh in cleanCoverage()).
        $coverage = (array) ($interview->coverage ?? []);
        $coverage[$section->value] = 'filled';
        $coverage['_skipped'] = collect((array) ($coverage['_skipped'] ?? []))
            ->push($section->value)->unique()->values()->all();
        $interview->update(['coverage' => $coverage]);

        return $this->ask($interview);
    }

    /** Free-form note injection ("owner said X off-script") — recorded, no question generated. */
    public function note(Interview $interview, string $content): InterviewTurn
    {
        return $interview->turns()->create([
            'role' => 'operator',
            'content' => '[Note] '.trim($content),
        ]);
    }

    /** End early — complete with thin sections allowed; the transcript persists as-is. */
    public function end(Interview $interview): Interview
    {
        $interview->update([
            'status' => InterviewStatus::Complete,
            'completed_at' => now(),
        ]);

        return $interview;
    }

    /** Ask the model for the next question; store + tag it and refresh the coverage meter. */
    private function ask(Interview $interview): InterviewTurn
    {
        $site = $interview->site;
        $raw = $this->claude->complete($this->conversationPrompt($interview), $this->systemPrompt($site));
        $parsed = $this->parse($raw);

        if (is_array($parsed['coverage'] ?? null)) {
            $interview->update(['coverage' => $this->cleanCoverage($parsed['coverage'], $interview)]);
        }

        return $interview->turns()->create([
            'role' => 'assistant',
            'content' => (string) ($parsed['question'] ?? trim($raw)),
            'section_tag' => InterviewSection::tryFrom((string) ($parsed['section'] ?? ''))?->value,
        ]);
    }

    private function systemPrompt(Site $site): string
    {
        $goals = collect(InterviewSection::cases())
            ->map(fn (InterviewSection $s) => "- {$s->value} ({$s->label()}): {$s->goal()}")
            ->implode("\n");

        return <<<PROMPT
You are conducting an intake interview for {$site->brand_name}, a local service business. An operator is on a call with the owner and types the owner's answers to you; you produce the next question, one at a time.

WHAT IS ALREADY KNOWN (imported — never re-ask any of it; reference it naturally in your questions):
{$this->knownContext($site)}

COVERAGE GOALS (goals, not a script — the conversation must eventually satisfy all five):
{$goals}

BEHAVIOR:
- Adapt: follow up on what the owner actually says (they mention French drains → probe the scope and cost drivers of French drains) before moving on.
- Skip goals the transcript already satisfies; circle back to thin ones. Respect any [Operator skipped …] instruction absolutely.
- One question per turn, conversational, phone-call length. Reference known facts by name.
- Never invent facts. Never ask for information already listed above.

RESPONSE FORMAT — respond with ONLY this JSON object, nothing else:
{"question": "<the next question to ask>", "section": "<trust|services|coverage|market_notes|voice>", "coverage": {"trust": "filled|thin|empty", "services": "filled|thin|empty", "coverage": "filled|thin|empty", "market_notes": "filled|thin|empty", "voice": "filled|thin|empty"}}

"coverage" is your honest self-assessment of the WHOLE transcript so far, per section.
PROMPT;
    }

    private function knownContext(Site $site): string
    {
        $lines = ["Business: {$site->brand_name}".($site->phone ? " · phone {$site->phone}" : '').($site->domain_url ? " · {$site->domain_url}" : '')];

        $trust = array_filter([
            $site->license_number !== null ? "license {$site->license_number}" : null,
            $site->insured !== null ? ($site->insured ? 'insured' : 'not insured') : null,
            $site->years_in_business !== null ? "{$site->years_in_business} years in business" : null,
            $site->warranty_program !== null ? 'warranty program on file' : null,
        ]);
        if ($trust !== []) {
            $lines[] = 'Trust facts on file: '.implode(', ', $trust);
        }

        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        foreach ($locations as $location) {
            $towns = collect($location->served_towns ?? [])->map(fn ($t) => trim((string) ($t['name'] ?? '')))->filter()->take(8);
            $lines[] = "Location: {$location->name}".($location->address ? " ({$location->address})" : '')
                .($towns->isNotEmpty() ? ' — serves '.$towns->join(', ') : ' — served towns unknown');
        }
        if ($locations->isEmpty()) {
            $lines[] = 'Locations: none imported yet.';
        }

        $services = Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('name');
        $lines[] = $services->isNotEmpty()
            ? 'Known services: '.$services->join(', ')
            : 'Known services: none on file yet.';

        return implode("\n", $lines);
    }

    private function conversationPrompt(Interview $interview): string
    {
        $turns = $interview->turns()->get();
        if ($turns->isEmpty()) {
            return 'The call is starting now. Produce your opening question (JSON as instructed).';
        }

        $lines = $turns->map(function (InterviewTurn $turn) {
            $who = $turn->role === 'assistant' ? 'YOU ASKED' : 'OWNER (via operator)';

            return "{$who}: {$turn->content}";
        })->implode("\n");

        return "TRANSCRIPT SO FAR:\n{$lines}\n\nProduce the next question (JSON as instructed).";
    }

    /**
     * Fence- and prose-tolerant parse: take the first {...last} JSON object; on failure treat the
     * whole reply as the question so the call never dead-ends on a formatting slip.
     *
     * @return array<string, mixed>
     */
    private function parse(string $raw): array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)) ?? $raw);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded) && trim((string) ($decoded['question'] ?? '')) !== '') {
                return $decoded;
            }
        }

        return ['question' => trim($raw)];
    }

    /**
     * @param  array<mixed>  $coverage
     * @return array<string, mixed>
     */
    private function cleanCoverage(array $coverage, Interview $interview): array
    {
        $skipped = (array) (((array) ($interview->coverage ?? []))['_skipped'] ?? []);

        $clean = [];
        foreach (InterviewSection::cases() as $section) {
            $value = (string) ($coverage[$section->value] ?? 'empty');
            $clean[$section->value] = in_array($section->value, $skipped, true)
                ? 'filled' // an operator skip outranks the model's self-assessment
                : (in_array($value, ['filled', 'thin', 'empty'], true) ? $value : 'empty');
        }
        if ($skipped !== []) {
            $clean['_skipped'] = $skipped;
        }

        return $clean;
    }
}
