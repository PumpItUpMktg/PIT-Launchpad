<?php

use App\Enums\InterviewAudience;
use App\Enums\InterviewStatus;
use App\Gathering\InterviewEngine;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Interview\Invites\InterviewInvites;
use App\Models\Account;
use App\Models\Interview;
use App\Models\InterviewTurn;
use App\Models\Site;
use App\Models\VoiceProfile;
use App\Support\CurrentSite;
use Tests\Support\SequencedClaudeClient;

function clientQuestion(string $question, string $section = 'trust', array $coverage = []): string
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

/** @param list<string> $replies */
function bindEngine(array $replies): SequencedClaudeClient
{
    $claude = new SequencedClaudeClient($replies);
    app()->instance(InterviewEngine::class, new InterviewEngine($claude));

    return $claude;
}

function clientSite(string $brand = 'Sump Pump Gurus'): Site
{
    $account = Account::factory()->create(['brand_name' => $brand, 'primary_color' => '#123456', 'accent_color' => '#abcdef']);

    return Site::factory()->create(['account_id' => $account->id, 'brand_name' => $brand]);
}

test('a live link opens the branded interview, creates the site interview with an owner-facing opener, and pins it on the invite', function (): void {
    $claude = bindEngine([clientQuestion('Welcome! In your own words, what does Sump Pump Gurus do?', 'services')]);
    $site = clientSite();
    $issued = app(InterviewInvites::class)->issue($site);

    $this->get(route('interview.show', $issued->plaintext))
        ->assertOk()
        ->assertSee('Sump Pump Gurus')
        ->assertSee('asked us to gather a few details only you know')   // who is asking and why
        ->assertSee('Private link')                                      // the security indication
        ->assertSee('--brand: #123456', false)                            // the tenant's own colours, not a hardcoded palette
        ->assertSee('what does Sump Pump Gurus do?')
        ->assertDontSee('Welcome back');

    $interview = Interview::withoutGlobalScopes()->where('site_id', $site->id)->sole();
    expect($interview->status)->toBe(InterviewStatus::InProgress)
        ->and($interview->turns()->count())->toBe(1)
        ->and($issued->invite->fresh()->interview_id)->toBe($interview->id)
        ->and($issued->invite->fresh()->open_count)->toBe(1);

    // The opener was framed for the owner, not for an operator on a call.
    expect($claude->prompts)->toHaveCount(1);
});

test('answering records an owner turn on the shared interview and shows the next question; the operator step reads the same row', function (): void {
    bindEngine([
        clientQuestion('What does the business do?', 'services'),
        clientQuestion('Are you licensed and insured?', 'trust', ['services' => 'filled']),
    ]);
    $site = clientSite();
    $issued = app(InterviewInvites::class)->issue($site);
    $this->get(route('interview.show', $issued->plaintext))->assertOk();

    $this->post(route('interview.answer', $issued->plaintext), ['answer' => 'We install and repair sump pumps across NJ.'])
        ->assertRedirect(route('interview.show', $issued->plaintext));

    $interview = Interview::withoutGlobalScopes()->where('site_id', $site->id)->sole();
    $roles = $interview->turns()->pluck('role')->all();
    expect($roles)->toBe(['assistant', 'owner', 'assistant'])
        ->and($interview->turns()->where('role', 'owner')->first()->content)->toBe('We install and repair sump pumps across NJ.');

    // The operator's step queries the site's latest interview — the very same row, now one owner turn richer.
    $operatorView = Interview::withoutGlobalScopes()->where('site_id', $site->id)->latest('started_at')->first();
    expect($operatorView->id)->toBe($interview->id);

    $this->get(route('interview.show', $issued->plaintext))
        ->assertOk()
        ->assertSee('Are you licensed and insured?')
        ->assertSee('Welcome back')
        ->assertSee('Your 1 answer is saved')
        ->assertSee('Services');
});

test('a returning client resumes the same interview — no second interview, the transcript intact', function (): void {
    bindEngine([clientQuestion('Q1', 'trust'), clientQuestion('Q2', 'services'), clientQuestion('Q3', 'coverage')]);
    $site = clientSite();
    $issued = app(InterviewInvites::class)->issue($site);

    $this->get(route('interview.show', $issued->plaintext));
    $this->post(route('interview.answer', $issued->plaintext), ['answer' => 'A1']);
    $this->post(route('interview.answer', $issued->plaintext), ['answer' => 'A2']);

    // A fresh visit (new session, days later) — same link.
    $this->flushSession();
    $this->get(route('interview.show', $issued->plaintext))
        ->assertOk()
        ->assertSee('Your 2 answers are saved')
        ->assertSee('A1')->assertSee('A2')->assertSee('Q3');

    expect(Interview::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(1);
});

test('finishing completes the interview (extraction stays with the operator) and activates nothing', function (): void {
    bindEngine([clientQuestion('Q1', 'trust')]);
    $site = clientSite();
    $issued = app(InterviewInvites::class)->issue($site);
    $this->get(route('interview.show', $issued->plaintext));

    $this->post(route('interview.finish', $issued->plaintext))->assertRedirect(route('interview.show', $issued->plaintext));

    $interview = Interview::withoutGlobalScopes()->where('site_id', $site->id)->sole();
    expect($interview->status)->toBe(InterviewStatus::Complete)
        ->and($interview->completed_at)->not->toBeNull()
        ->and(VoiceProfile::withoutGlobalScopes()->count())->toBe(0); // draft-only guard: the public path never touches voice

    $this->get(route('interview.show', $issued->plaintext))->assertOk()->assertSee('that\'s everything we need');
    // A late answer to a completed interview is ignored, not appended.
    $this->post(route('interview.answer', $issued->plaintext), ['answer' => 'late'])->assertRedirect();
    expect($interview->turns()->count())->toBe(1);
});

test('an expired, revoked, or tampered token shows the neutral expired page, leaks no brand, and creates nothing', function (): void {
    bindEngine([clientQuestion('Q1')]);
    $site = clientSite('Very Secret Plumbing');
    $invites = app(InterviewInvites::class);

    $tampered = $invites->issue($site);
    $bad = substr($tampered->plaintext, 0, -1).($tampered->plaintext[-1] === 'a' ? 'b' : 'a');
    $this->get(route('interview.show', $bad))->assertOk()->assertSee('no longer active')->assertDontSee('Very Secret Plumbing');

    $revoked = $invites->issue($site);
    $invites->revoke($revoked->invite);
    $this->get(route('interview.show', $revoked->plaintext))->assertOk()->assertSee('no longer active')->assertDontSee('Very Secret Plumbing');
    $this->post(route('interview.answer', $revoked->plaintext), ['answer' => 'x'])->assertOk()->assertSee('no longer active');

    $expired = $invites->issue($site, ttlDays: 1);
    $this->travel(2)->days();
    $this->get(route('interview.show', $expired->plaintext))->assertOk()->assertSee('no longer active');
    $this->travelBack();

    expect(Interview::withoutGlobalScopes()->count())->toBe(0);
});

test('a manipulated token or parameter can never reach another tenant\'s interview', function (): void {
    bindEngine([clientQuestion('Question for A', 'trust'), clientQuestion('Next for A', 'services')]);
    $siteA = clientSite('Tenant A Plumbing');
    $siteB = clientSite('Tenant B Drains');
    $invites = app(InterviewInvites::class);
    $linkA = $invites->issue($siteA);

    // Tenant B already has an in-progress interview with an answer in it.
    $interviewB = Interview::factory()->create(['site_id' => $siteB->id]);
    $interviewB->turns()->create(['role' => 'assistant', 'content' => 'Secret question for B']);
    $interviewB->turns()->create(['role' => 'operator', 'content' => 'Secret answer from B']);

    // Every parameter a client could add is ignored — only the token decides the tenant.
    $this->get(route('interview.show', $linkA->plaintext).'?site='.$siteB->id.'&interview='.$interviewB->id)
        ->assertOk()
        ->assertSee('Tenant A Plumbing')
        ->assertSee('Question for A')
        ->assertDontSee('Tenant B Drains')
        ->assertDontSee('Secret question for B')
        ->assertDontSee('Secret answer from B');

    $this->post(route('interview.answer', $linkA->plaintext), [
        'answer' => 'From A', 'site_id' => $siteB->id, 'interview_id' => $interviewB->id, 'site' => $siteB->id,
    ])->assertRedirect();

    expect($interviewB->turns()->count())->toBe(2)                         // B untouched
        ->and($interviewB->turns()->where('content', 'From A')->exists())->toBeFalse()
        ->and(Interview::withoutGlobalScopes()->where('site_id', $siteA->id)->sole()->turns()->where('role', 'owner')->first()->content)->toBe('From A');

    // B's stored hash is not a token; a token for B cannot be derived from A's; an unknown token binds nothing.
    $this->get(route('interview.show', $linkA->invite->token))->assertSee('no longer active');
    expect(CurrentSite::id())->not->toBe($siteB->id);
});

test('a model failure after the answer is saved offers a retry — nothing is lost, nothing is duplicated', function (): void {
    $failing = new class implements ClaudeClient
    {
        public function complete(string $prompt, ?string $system = null): string
        {
            throw new RuntimeException('upstream 503');
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            throw new RuntimeException('upstream 503');
        }
    };
    $site = clientSite();
    $issued = app(InterviewInvites::class)->issue($site);

    // Opener works, then the model fails on the first answer.
    bindEngine([clientQuestion('Q1', 'trust')]);
    $this->get(route('interview.show', $issued->plaintext))->assertOk();
    app()->instance(InterviewEngine::class, new InterviewEngine($failing));
    $this->post(route('interview.answer', $issued->plaintext), ['answer' => 'My answer'])->assertRedirect();

    $interview = Interview::withoutGlobalScopes()->where('site_id', $site->id)->sole();
    expect($interview->turns()->pluck('role')->all())->toBe(['assistant', 'owner']);

    $this->get(route('interview.show', $issued->plaintext))
        ->assertOk()
        ->assertSee('Your last answer is saved')
        ->assertSee('Get the next question');

    // Retry with the model back: one new question, the answer not re-recorded.
    bindEngine([clientQuestion('Q2', 'services')]);
    $this->post(route('interview.retry', $issued->plaintext))->assertRedirect();
    expect($interview->turns()->pluck('role')->all())->toBe(['assistant', 'owner', 'assistant'])
        ->and(InterviewTurn::query()->where('content', 'My answer')->count())->toBe(1);
});

test('the engine frames the opener for the owner on the client link and for the operator on the call', function (): void {
    $site = clientSite();
    $claude = new class([clientQuestion('Q')]) extends SequencedClaudeClient
    {
        /** @var list<string> */
        public array $systems = [];

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            $this->systems[] = (string) $system;

            return parent::completeDetailed($prompt, $system);
        }
    };
    $engine = new InterviewEngine($claude);

    $engine->start($site, InterviewAudience::Owner);
    expect($claude->systems[0])->toContain('typing their own answers')->not->toContain('operator is on a call');

    Interview::withoutGlobalScopes()->delete();
    $engine->start($site);
    expect($claude->systems[1])->toContain('operator is on a call');
});
