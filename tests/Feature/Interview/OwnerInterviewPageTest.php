<?php

use App\Enums\UserRole;
use App\Filament\Pages\OwnerInterview;
use App\Integrations\Claude\ClaudeClient;
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

it('runs the chat from start to a persisted seed + voice', function () {
    $site = Site::factory()->create();

    $this->app->instance(ClaudeClient::class, new SequencedClaudeClient([
        (string) json_encode(['message' => 'What do you do?', 'ready' => false]),
        (string) json_encode(['message' => 'Got it — that is enough.', 'ready' => true]),
        (string) json_encode([
            'seed' => [
                'trade' => 'roofing',
                'anchor_services' => ['Roof Replacement'],
                'markets' => ['Denver'],
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
        ->call('extract')
        ->assertSet('preview.seed.trade', 'roofing')
        ->call('persist')
        ->assertSet('persisted', true);

    expect(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('trade'))
        ->toBe('roofing');
    expect(VoiceProfile::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)->where('status', 'active')->count())->toBe(1);
});
