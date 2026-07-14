<?php

use App\Enums\InterviewSection;
use App\Enums\InterviewStatus;
use App\Gathering\InterviewEngine;
use App\Integrations\Claude\CompletionResult;
use App\Models\Interview;
use App\Models\Location;
use App\Models\Service;
use App\Models\Site;
use Tests\Support\SequencedClaudeClient;

function engineJson(string $question, string $section = 'trust', array $coverage = []): string
{
    return json_encode([
        'question' => $question,
        'section' => $section,
        'coverage' => array_merge(
            ['trust' => 'empty', 'services' => 'empty', 'coverage' => 'empty', 'market_notes' => 'empty', 'voice' => 'empty'],
            $coverage,
        ),
    ]);
}

it('opens context-aware — the prompt carries imported locations and services, never re-asks them', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Geeks']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation']);

    // Capture the SYSTEM prompt too — the known-context block lives there.
    $claude = new class([engineJson('You have locations in Trooper and Montclair — do they cover different areas?', 'coverage')]) extends SequencedClaudeClient
    {
        /** @var list<string> */
        public array $systems = [];

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            $this->systems[] = (string) $system;

            return parent::completeDetailed($prompt, $system);
        }
    };
    $interview = (new InterviewEngine($claude))->start($site);

    $sent = implode("\n", $claude->systems);
    expect($sent)->toContain('Trooper')
        ->toContain('Montclair')
        ->toContain('Sump Pump Installation')
        ->and($interview->status)->toBe(InterviewStatus::InProgress)
        ->and($interview->turns()->count())->toBe(1)
        ->and($interview->turns()->first()->role)->toBe('assistant')
        ->and($interview->turns()->first()->section_tag)->toBe('coverage');
});

it('adapts turn by turn — answers are recorded, questions tagged, the coverage meter moves', function () {
    $site = Site::factory()->create();
    $claude = new SequencedClaudeClient([
        engineJson('Are you licensed and insured?', 'trust'),
        engineJson('You mentioned French drains — what does that job include?', 'services', ['trust' => 'filled', 'services' => 'thin']),
    ]);
    $engine = new InterviewEngine($claude);

    $interview = $engine->start($site);
    $engine->answer($interview, 'Licensed in PA, fully insured. We also do French drains.');

    $interview->refresh();
    expect($interview->turns()->count())->toBe(3) // opener, answer, follow-up
        ->and($interview->turns->last()->content)->toContain('French drains')
        ->and($interview->turns->last()->section_tag)->toBe('services')
        ->and($interview->coverage['trust'])->toBe('filled')
        ->and($interview->coverage['services'])->toBe('thin');
});

it('resumes the open interview (no duplicate), skips sections, and ends early with the transcript intact', function () {
    $site = Site::factory()->create();
    $claude = new SequencedClaudeClient([
        engineJson('Opening question?', 'trust'),
        engineJson('Moving on — who is your typical customer?', 'voice'),
    ]);
    $engine = new InterviewEngine($claude);

    $first = $engine->start($site);
    $resumed = $engine->start($site); // resume, not restart
    expect($resumed->id)->toBe($first->id)
        ->and(Interview::query()->where('site_id', $site->id)->count())->toBe(1);

    $engine->skipSection($first, InterviewSection::MarketNotes);
    $first->refresh();
    expect($first->coverage['market_notes'])->toBe('filled') // skipped = satisfied for the meter
        ->and($first->turns->firstWhere('role', 'operator')->content)->toContain('skipped the Market notes');

    $engine->note($first, 'Owner mentioned they sponsor the Trooper little league.');
    $engine->end($first);
    $first->refresh();
    expect($first->status)->toBe(InterviewStatus::Complete)
        ->and($first->completed_at)->not->toBeNull()
        ->and($first->turns()->count())->toBeGreaterThanOrEqual(4); // transcript preserved
});

it('degrades gracefully when the model returns prose instead of JSON', function () {
    $site = Site::factory()->create();
    $claude = new SequencedClaudeClient(['Just tell me about your business.']);

    $interview = (new InterviewEngine($claude))->start($site);

    expect($interview->turns()->first()->content)->toBe('Just tell me about your business.')
        ->and($interview->turns()->first()->section_tag)->toBeNull();
});
