<?php

use App\Interview\Conversation\Interviewer;
use App\Interview\Conversation\InterviewSession;
use App\Interview\Conversation\Turn;
use App\Interview\InterviewExtractor;
use App\Interview\SeedValidator;
use Tests\Support\FakeClaudeClient;
use Tests\Support\SequencedClaudeClient;

function ask(string $message, bool $ready = false): string
{
    return (string) json_encode(['message' => $message, 'ready' => $ready]);
}

test('it drives a multi-turn interview and signals ready when it has enough', function () {
    $claude = new SequencedClaudeClient([
        ask('What kind of work do you do?'),                       // opening
        ask('Which neighborhoods do you serve?'),                  // after answer 1
        ask('Thanks — that gives me what I need.', ready: true),   // after answer 2
    ]);
    $interviewer = new Interviewer($claude);

    $session = InterviewSession::start($interviewer);
    expect($session->isReady())->toBeFalse()
        ->and($session->turns())->toHaveCount(1)
        ->and($session->turns()[0]->role)->toBe(Turn::ASSISTANT);

    $session->submit($interviewer, 'We waterproof basements and install sump pumps.');
    expect($session->isReady())->toBeFalse();

    $reply = $session->submit($interviewer, 'All around Tucson. We do not do roofing.');
    expect($reply->ready)->toBeTrue()
        ->and($session->isReady())->toBeTrue()
        ->and($session->turns())->toHaveCount(5); // 3 assistant + 2 owner
});

test('it falls back to a sane question when the model returns no message', function () {
    $interviewer = new Interviewer(new FakeClaudeClient('not json'));

    $session = InterviewSession::start($interviewer);

    expect($session->turns()[0]->text)->not->toBe('')
        ->and($session->isReady())->toBeFalse();
});

test('a session round-trips through array losslessly', function () {
    $session = InterviewSession::fromArray([
        ['role' => 'assistant', 'text' => 'What do you do?'],
        ['role' => 'owner', 'text' => 'Plumbing.'],
    ]);

    $array = $session->toArray();

    expect($array)->toHaveCount(2)
        ->and($array[1])->toBe(['role' => 'owner', 'text' => 'Plumbing.']);
});

test('a ready session extracts a seed + voice over the full transcript', function () {
    $interviewer = new Interviewer(new SequencedClaudeClient([
        ask('Tell me about your business.'),
        ask('Great, I have what I need.', ready: true),
    ]));

    $session = InterviewSession::start($interviewer);
    $session->submit($interviewer, 'HVAC — AC and furnace work across Phoenix.');

    $extractorJson = (string) json_encode([
        'seed' => [
            'trade' => 'hvac',
            'anchor_services' => ['AC Repair', 'Furnace Installation'],
            'markets' => ['Phoenix'],
            'exclusions' => [],
        ],
        'voice' => [
            'framing_model' => 'problem_solution',
            'tone_axes' => ['formality' => 0.4, 'warmth' => 0.7],
        ],
    ]);
    $extractor = new InterviewExtractor(new FakeClaudeClient($extractorJson), new SeedValidator);

    $result = $session->extract($extractor);

    expect($result->seed->trade)->toBe('hvac')
        ->and($result->seed->markets)->toContain('Phoenix')
        ->and($result->voice['framing_model'])->toBe('problem_solution');
});
