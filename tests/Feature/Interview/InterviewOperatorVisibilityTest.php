<?php

use App\Enums\InterviewStatus;
use App\Enums\LobbyBadgeTier;
use App\Enums\LobbyCardState;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\InterviewStep;
use App\Gathering\InterviewEngine;
use App\Gathering\SetupProgress;
use App\Interview\Invites\InterviewInvites;
use App\Mail\InterviewCompletedMail;
use App\Mail\InterviewInviteMail;
use App\Models\Interview;
use App\Models\Site;
use App\Models\User;
use App\Operator\Lobby\LobbyBoard;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\SequencedClaudeClient;

afterEach(fn () => CurrentSite::clear());

function visibilityCard(string $siteId)
{
    return app(LobbyBoard::class)->cards()->firstWhere(fn ($c) => $c->site->id === $siteId);
}

function ownerAnsweredInterview(Site $site, InterviewStatus $status = InterviewStatus::InProgress, array $coverage = []): Interview
{
    $interview = Interview::factory()->create([
        'site_id' => $site->id, 'status' => $status, 'coverage' => $coverage,
        'completed_at' => $status === InterviewStatus::Complete ? now() : null,
    ]);
    $interview->turns()->create(['role' => 'assistant', 'content' => 'Q1', 'section_tag' => 'trust']);
    $interview->turns()->create(['role' => 'owner', 'content' => 'A1']);

    return $interview;
}

test('the lobby badges a client-answered interview quietly (Tier 4) — on an onboarding card too', function () {
    $onboarding = Site::factory()->create(['status' => SiteStatus::Onboarding]);
    ownerAnsweredInterview($onboarding);

    $card = visibilityCard($onboarding->id);
    expect($card->state)->toBe(LobbyCardState::Onboarding)
        ->and($card->visibleBadges())->toHaveCount(1)
        ->and($card->visibleBadges()[0]->key)->toBe('client_interview_in_progress')
        ->and($card->visibleBadges()[0]->tier)->toBe(LobbyBadgeTier::Degrading);
});

test('a newly completed client interview badges as ready to extract; an operator-led call never badges', function () {
    $done = Site::factory()->create(['status' => SiteStatus::Onboarding]);
    ownerAnsweredInterview($done, InterviewStatus::Complete);

    $operatorLed = Site::factory()->create(['status' => SiteStatus::Onboarding]);
    $call = Interview::factory()->create(['site_id' => $operatorLed->id]);
    $call->turns()->create(['role' => 'assistant', 'content' => 'Q1']);
    $call->turns()->create(['role' => 'operator', 'content' => 'typed by the operator']);

    $badge = visibilityCard($done->id)->visibleBadges()[0];
    expect($badge->key)->toBe('client_interview_complete')
        ->and($badge->detail)->toBe('ready to extract')
        ->and(visibilityCard($operatorLed->id)->badges)->toBe([]);

    // Eight days later the "newly completed" badge retires.
    $this->travel(8)->days();
    expect(visibilityCard($done->id)->badges)->toBe([]);
    $this->travelBack();
});

test('the Setup rail shows sections covered and who is answering', function () {
    $site = Site::factory()->create();
    expect(app(SetupProgress::class)->interviewDetail($site))->toBeNull();

    ownerAnsweredInterview($site, coverage: ['trust' => 'filled', 'services' => 'filled', 'coverage' => 'thin']);
    expect(app(SetupProgress::class)->interviewDetail($site))->toBe('2/5 covered · client answering');

    $steps = app(SetupProgress::class)->steps($site);
    expect(collect($steps)->firstWhere('label', 'Interview')['detail'])->toBe('2/5 covered · client answering')
        ->and(collect($steps)->firstWhere('label', 'Business')['detail'])->toBeNull();
});

test('the operator can send, resend, and revoke the client link from the Interview step', function () {
    Mail::fake();
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $this->actingAs($operator);
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    session(['guided_site_id' => $site->id]);
    $invites = app(InterviewInvites::class);

    $page = Livewire::test(InterviewStep::class)
        ->assertSee('No live link')
        ->set('inviteEmail', 'owner@example.com')
        ->call('sendLink')
        ->assertSee('Shown once');

    $first = $invites->live($site);
    expect($first)->not->toBeNull()
        ->and($first->recipient_email)->toBe('owner@example.com')
        ->and($first->issued_by)->toBe((string) $operator->id)
        ->and($page->instance()->issuedLink)->toContain('/interview/');
    // Sent, not queued: the operator is on the page to learn whether it went, and queued it sat behind
    // page generation while the page claimed "Link sent".
    Mail::assertSent(InterviewInviteMail::class, fn (InterviewInviteMail $m) => $m->inviteId === (string) $first->id);
    Mail::assertNotQueued(InterviewInviteMail::class);

    // Resend → a new live link, the first one dead.
    $page->call('sendLink');
    $second = $invites->live($site);
    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->revoked_at)->not->toBeNull();

    // Revoke → nothing live, the readiness copy falls back.
    $page->call('revokeLink')->assertSee('No live link');
    expect($invites->live($site))->toBeNull();

    // A bad address is refused without issuing.
    $page->set('inviteEmail', 'not-an-email')->call('sendLink');
    expect($invites->live($site))->toBeNull();
});

test('the Interview step readiness and transcript reflect the client\'s answers', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    ownerAnsweredInterview($site);

    $page = Livewire::test(InterviewStep::class)->assertSee('A1');
    expect($page->instance()->readiness()['label'])->toBe('In progress — client answering (1 answer so far)')
        ->and($page->instance()->clientAnswers)->toBe(1);
});

test('finishing on the client link emails the operator who issued it', function () {
    Mail::fake();
    $operator = User::factory()->create(['role' => UserRole::Operator, 'email' => 'ops@example.com']);
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    app()->instance(InterviewEngine::class, new InterviewEngine(new SequencedClaudeClient([
        json_encode(['question' => 'Q1', 'section' => 'trust', 'coverage' => []]),
    ])));
    $issued = app(InterviewInvites::class)->issue($site, 'owner@example.com', (string) $operator->id);

    $this->get(route('interview.show', $issued->plaintext))->assertOk();
    $this->post(route('interview.finish', $issued->plaintext))->assertRedirect();

    Mail::assertQueued(InterviewCompletedMail::class, fn (InterviewCompletedMail $m) => $m->siteId === (string) $site->id
        && $m->hasTo('ops@example.com'));
    // Finishing twice never emails twice — the second finish is a no-op on a completed interview.
    $this->post(route('interview.finish', $issued->plaintext))->assertRedirect();
    Mail::assertQueued(InterviewCompletedMail::class, 1);
});

test('a failed invite email is reported as a failure, and the link is still issued', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $this->actingAs($operator);
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    session(['guided_site_id' => $site->id]);

    // The mail API is down. "Link sent" would be a lie; the link itself is still good.
    Mail::shouldReceive('to')->once()->andReturnUsing(function (): never {
        throw new RuntimeException('Connection refused by mail API');
    });

    $page = Livewire::test(InterviewStep::class)
        ->set('inviteEmail', 'owner@example.com')
        ->call('sendLink')
        ->assertNotified('Could not email owner@example.com');

    expect(app(InterviewInvites::class)->live($site))->not->toBeNull()
        ->and($page->instance()->issuedLink)->toContain('/interview/');
});

test('an invite "sent" to the log mailer is called out as sent nowhere', function () {
    Mail::fake();
    config(['mail.default' => 'log']);
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $this->actingAs($operator);
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    session(['guided_site_id' => $site->id]);

    // MAIL_MAILER=log is the framework default and this repo's .env.example. It succeeds silently and
    // nobody receives anything — the exact shape of "the owner never got the email".
    Livewire::test(InterviewStep::class)
        ->set('inviteEmail', 'owner@example.com')
        ->call('sendLink')
        ->assertNotified('Mail is set to log — nothing was actually emailed');
});
