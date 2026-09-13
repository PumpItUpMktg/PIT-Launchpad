<?php

use App\Interview\Invites\InterviewInvites;
use App\Models\InterviewInvite;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;

test('issuing stores only a sha-256 hash and the configured expiry', function (): void {
    config(['launchpad.interview_invite_ttl_days' => 30]);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $site = Site::factory()->create();

    $issued = app(InterviewInvites::class)->issue($site, 'owner@example.com');

    expect(strlen($issued->plaintext))->toBe(48)
        ->and($issued->invite->token)->toBe(hash('sha256', $issued->plaintext))
        ->and(strlen($issued->invite->token))->toBe(64)
        ->and($issued->invite->token)->not->toBe($issued->plaintext)
        ->and($issued->invite->expires_at->toDateTimeString())->toBe('2026-10-14 10:00:00')
        ->and($issued->invite->recipient_email)->toBe('owner@example.com')
        ->and($issued->invite->isLive())->toBeTrue();

    Carbon::setTestNow();
});

test('the link is multi-use: resolving and binding it any number of times never spends it', function (): void {
    $site = Site::factory()->create();
    $invites = app(InterviewInvites::class);
    $issued = $invites->issue($site);

    foreach (range(1, 3) as $n) {
        $invite = $invites->find($issued->plaintext);
        expect($invite)->not->toBeNull()->and($invite->isLive())->toBeTrue();
        $bound = $invites->bind($invite);
        expect($bound->id)->toBe($site->id)
            ->and(CurrentSite::id())->toBe($site->id)
            ->and($invite->fresh()->open_count)->toBe($n);
        CurrentSite::set(null);
    }
});

test('resolution is scope-free — the token carries the tenant even while another tenant is locked', function (): void {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $invites = app(InterviewInvites::class);
    $issued = $invites->issue($siteA);

    CurrentSite::set($siteB->id); // the panel's lock on a different tenant must not hide (or reroute) the link

    $invite = $invites->find($issued->plaintext);
    expect($invite)->not->toBeNull()->and($invite->site_id)->toBe($siteA->id);

    // Binding re-locks to the link's own tenant — never the one that happened to be set.
    expect($invites->bind($invite)->id)->toBe($siteA->id)
        ->and(CurrentSite::id())->toBe($siteA->id);
    CurrentSite::set(null);
});

test('a tampered or unknown token resolves to nothing', function (): void {
    $site = Site::factory()->create();
    $invites = app(InterviewInvites::class);
    $issued = $invites->issue($site);

    $tampered = substr($issued->plaintext, 0, -1).($issued->plaintext[-1] === 'a' ? 'b' : 'a');

    expect($invites->find($tampered))->toBeNull()
        ->and($invites->find($issued->invite->token))->toBeNull() // the stored hash is not a usable token
        ->and($invites->find(''))->toBeNull();
});

test('an expired or revoked link resolves but is not live', function (): void {
    $invites = app(InterviewInvites::class);
    $site = Site::factory()->create();

    $expired = $invites->issue($site, ttlDays: 1);
    Carbon::setTestNow(now()->addDays(2));
    expect($invites->find($expired->plaintext)?->isLive())->toBeFalse();
    Carbon::setTestNow();

    $revoked = $invites->issue($site);
    $invites->revoke($revoked->invite);
    expect($invites->find($revoked->plaintext)?->isLive())->toBeFalse()
        ->and($invites->live($site))->toBeNull();
});

test('issuing again revokes the previous link — one live link per site, the old one dies', function (): void {
    $invites = app(InterviewInvites::class);
    $site = Site::factory()->create();
    $other = Site::factory()->create();

    $first = $invites->issue($site);
    $otherLink = $invites->issue($other);
    $second = $invites->issue($site);

    expect($invites->find($first->plaintext)?->isLive())->toBeFalse()
        ->and($invites->find($second->plaintext)?->isLive())->toBeTrue()
        ->and($invites->live($site)?->id)->toBe($second->invite->id)
        ->and($invites->find($otherLink->plaintext)?->isLive())->toBeTrue() // another tenant's link is untouched
        ->and(InterviewInvite::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('revoked_at')->count())->toBe(1);
});

test('the command issues, shows, and revokes a link', function (): void {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $invites = app(InterviewInvites::class);

    $this->artisan('launchpad:interview-invite', ['site' => 'Sump Pump Gurus', '--ttl' => '14'])
        ->assertSuccessful()
        ->expectsOutputToContain('Token:');
    $live = $invites->live($site);
    expect($live)->not->toBeNull()
        ->and((int) $live->issued_at->diffInDays($live->expires_at))->toBe(14);

    $this->artisan('launchpad:interview-invite', ['site' => $site->id, '--status' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Live link issued');

    $this->artisan('launchpad:interview-invite', ['site' => $site->id, '--revoke' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('revoked 1 link(s)');
    expect($invites->live($site))->toBeNull();

    $this->artisan('launchpad:interview-invite', ['site' => 'nope'])->assertFailed();
});
