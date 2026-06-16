<?php

use App\Enums\UserRole;
use App\Filament\Pages\OwnerInterview;
use App\Integrations\Claude\ClaudeClient;
use App\Interview\ExtractionResult;
use App\Interview\InterviewPersister;
use App\Interview\SiloSeed;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\User;
use App\Models\VoiceProfile;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\SequencedClaudeClient;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('runs the chat, lets the operator edit the seed, and persists seed + transcript + voice', function () {
    $site = Site::factory()->create();

    $this->app->instance(ClaudeClient::class, new SequencedClaudeClient([
        (string) json_encode(['message' => 'What do you do?', 'ready' => false]),
        (string) json_encode(['message' => 'Got it — that is enough.', 'ready' => true]),
        (string) json_encode([
            'seed' => [
                'trade' => 'roofing',
                'anchor_services' => ['Roof Replacement'],
                'region' => 'Denver metro',
                'exclusions' => [],
            ],
            'voice' => ['framing_model' => 'problem_solution', 'tone_axes' => ['warmth' => 0.6]],
        ]),
    ]));

    Livewire::test(OwnerInterview::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->assertSet('started', true)
        ->assertSet('ready', false)
        ->set('draft', 'We replace roofs across Denver.')
        ->call('send')
        ->assertSet('ready', true)
        // wrap must TRANSITION, not dead-end: the forward CTA + next-step note are present.
        ->assertSee('review the details below')
        ->assertSee('Extract seed + voice')
        ->call('extract')
        ->assertSet('extracted', true)
        ->assertSet('editTrade', 'roofing')
        ->assertSet('editRegion', 'Denver metro')
        // operator sanity-edit: add an exclusion the owner mentioned in passing
        ->set('editExclusions', 'Commercial work')
        ->call('persist')
        ->assertSet('persisted', true);

    $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
    expect($blueprint->trade)->toBe('roofing')
        ->and($blueprint->seed['exclusions'])->toBe(['Commercial work']) // the edit won
        ->and($blueprint->transcript)->toHaveCount(3); // assistant, owner, assistant

    expect(VoiceProfile::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)->where('status', 'active')->count())->toBe(1);
});

it('offers a persistent extract control before wrap so the owner can advance any time', function () {
    $site = Site::factory()->create();

    $this->app->instance(ClaudeClient::class, new SequencedClaudeClient([
        (string) json_encode(['message' => 'Tell me about your business.', 'ready' => false]),
        (string) json_encode([
            'seed' => ['trade' => 'plumbing', 'anchor_services' => ['Drain Cleaning'], 'region' => '', 'exclusions' => []],
            'voice' => ['framing_model' => 'problem_solution', 'tone_axes' => []],
        ]),
    ]));

    Livewire::test(OwnerInterview::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->assertSet('ready', false)
        ->assertSee('extract now')   // always-available forward action
        ->call('extract')            // advancing mid-interview works (extract never requires ready)
        ->assertSet('extracted', true)
        ->assertSet('editTrade', 'plumbing');
});

it('resumes a saved interview — transcript, seed, and voice all reload', function () {
    $site = Site::factory()->create();

    $transcript = [
        ['role' => 'assistant', 'text' => 'Tell me about your business.'],
        ['role' => 'owner', 'text' => 'HVAC across Phoenix.'],
    ];
    app(InterviewPersister::class)->persist(
        $site,
        new ExtractionResult(
            new SiloSeed('hvac', ['AC Repair'], 'Phoenix area', []),
            ['framing_model' => 'problem_solution', 'tone_axes' => ['warmth' => 0.5], 'cta_voice' => 'direct'],
        ),
        $transcript,
    );

    Livewire::test(OwnerInterview::class)
        ->set('siteId', $site->id)
        ->assertSet('hasSaved', true)
        ->call('resume')
        ->assertSet('started', true)
        ->assertSet('extracted', true)
        ->assertSet('editTrade', 'hvac')
        ->assertSet('editAnchors', 'AC Repair')
        ->assertSet('editRegion', 'Phoenix area')
        ->assertSet('voice.cta_voice', 'direct')
        ->assertCount('messages', 2);
});

it('resumes a legacy seed (markets[] before the region reframe) into the broad Region field', function () {
    $site = Site::factory()->create();
    SiloBlueprint::factory()->create([
        'site_id' => $site->id,
        'trade' => 'waterproofing',
        'seed' => [
            'trade' => 'waterproofing',
            'anchor_services' => ['Sump Pump Installation'],
            'markets' => ['NJ', 'eastern PA'], // legacy shape, no 'region' key
            'exclusions' => [],
        ],
    ]);

    Livewire::test(OwnerInterview::class)
        ->set('siteId', $site->id)
        ->call('resume')
        ->assertSet('editRegion', 'NJ, eastern PA');
});
