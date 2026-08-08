<?php

use App\Filament\Pages\Gathering\InterviewStep;
use App\Gathering\InterviewEngine;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Models\Interview;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\FakeClaudeClient;

/** A ClaudeClient that always fails — stands in for a transient API outage/timeout. */
function downClaude(): ClaudeClient
{
    return new class implements ClaudeClient
    {
        public function complete(string $prompt, ?string $system = null): string
        {
            throw new RuntimeException('api down');
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            throw new RuntimeException('api down');
        }
    };
}

function questionJson(string $q): string
{
    return json_encode(['question' => $q, 'section' => 'services', 'coverage' => []]);
}

it('persists the owner answer when the model call fails, and resume() recovers the question', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Geeks']);

    // A working first question.
    $interview = (new InterviewEngine(new FakeClaudeClient(questionJson('What do you install?'))))->start($site);
    expect($interview->turns()->count())->toBe(1);

    // The next turn's model call fails — the operator turn is still saved, then ask() throws.
    expect(fn () => (new InterviewEngine(downClaude()))->answer($interview, 'We install sump pumps'))
        ->toThrow(RuntimeException::class);
    expect($interview->turns()->count())->toBe(2)
        ->and($interview->turns()->reorder()->orderByDesc('id')->first()->role)->toBe('operator');

    // resume() regenerates the question WITHOUT recording a second operator turn.
    $q = (new InterviewEngine(new FakeClaudeClient(questionJson('Any brands you specialize in?'))))->resume($interview);
    expect($q?->role)->toBe('assistant')
        ->and($interview->turns()->count())->toBe(3);

    // resume() is a no-op once a question is already pending (safe to click twice).
    expect((new InterviewEngine(new FakeClaudeClient(questionJson('x'))))->resume($interview))->toBeNull()
        ->and($interview->turns()->count())->toBe(3);
});

it('send() shows a notice instead of crashing the page, and Ask-again recovers', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Geeks']);
    session(['guided_site_id' => $site->id]);

    // An in-progress interview with a first question already asked.
    (new InterviewEngine(new FakeClaudeClient(questionJson('What do you install?'))))->start($site);

    // The model is down: send() must NOT throw (no "error loading this page"); it saves the answer.
    // InterviewEngine gets its ClaudeClient via a contextual binding, so bind the engine itself.
    app()->instance(InterviewEngine::class, new InterviewEngine(downClaude()));

    Livewire::test(InterviewStep::class)
        ->set('siteId', $site->id)
        ->set('input', 'We install sump pumps')
        ->call('send')
        ->assertOk()
        ->assertSet('input', '');

    $interview = Interview::query()->where('site_id', $site->id)->latest('started_at')->first();
    expect($interview->turns()->count())->toBe(2); // Q1 + saved answer, no assistant reply yet

    // Now the model recovers — Ask again produces the next question.
    app()->instance(InterviewEngine::class, new InterviewEngine(new FakeClaudeClient(questionJson('Any brands you specialize in?'))));

    Livewire::test(InterviewStep::class)
        ->set('siteId', $site->id)
        ->call('retryQuestion')
        ->assertOk();

    expect($interview->turns()->count())->toBe(3)
        ->and($interview->turns()->reorder()->orderByDesc('id')->first()->role)->toBe('assistant');
});
