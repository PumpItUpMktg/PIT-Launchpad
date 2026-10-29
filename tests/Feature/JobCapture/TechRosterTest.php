<?php

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Filament\Console\Pages\UsersAccess;
use App\JobCapture\Auth\DeviceAuthenticator;
use App\JobCapture\Auth\TechProvisioner;
use App\JobCapture\Auth\TechRoster;
use App\Mail\TechCaptureInviteMail;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Service;
use App\Models\Site;
use App\Models\TechDevice;
use App\Models\User;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->admin = User::factory()->create(); // Super Admin
    $this->gurus = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $this->other = Site::factory()->create(['brand_name' => 'Acme Roofing']);
});

/** Sign a device in through the real flow so it reads ACTIVE. */
function rosterSignIn(TechDevice $device): void
{
    $auth = app(DeviceAuthenticator::class);
    $code = $auth->issueLoginCode($device);
    $auth->redeemLoginCode($device->fresh(), $code);
}

test('status is derived: invited until the first sign-in, active after, inactive once revoked', function (): void {
    $device = TechDevice::factory()->create(['site_id' => $this->gurus->id]);
    expect(TechRoster::status($device))->toBe(TechRoster::INVITED);

    rosterSignIn($device);
    expect(TechRoster::status($device->fresh()))->toBe(TechRoster::ACTIVE);

    app(TechRoster::class)->revoke($device->fresh());
    expect(TechRoster::status($device->fresh()))->toBe(TechRoster::INACTIVE);
});

test('the roster is one row per device, grouped by client, only the clients the user may see', function (): void {
    TechDevice::factory()->create(['site_id' => $this->gurus->id, 'name' => 'Mike R.']);
    TechDevice::factory()->revoked()->create(['site_id' => $this->gurus->id, 'name' => 'Old Mike']);
    TechDevice::factory()->create(['site_id' => $this->other->id, 'name' => 'Roofer Ray']);
    Site::factory()->create(['brand_name' => 'Nobody Here']); // no devices → not listed

    $groups = app(TechRoster::class)->forUser($this->admin);
    expect(array_column($groups, 'site'))->toBe(['Acme Roofing', 'Sump Pump Gurus'])
        ->and(collect($groups[1]['techs'])->pluck('status_label', 'name')->all())->toBe(['Mike R.' => 'Invited', 'Old Mike' => 'Inactive']);

    // A Site Admin of one client sees only that client's techs.
    $account = Account::factory()->create();
    $this->gurus->update(['account_id' => $account->id]);
    $siteAdmin = User::factory()->siteAdmin()->create();
    Membership::create(['user_id' => $siteAdmin->id, 'account_id' => $account->id, 'site_id' => $this->gurus->id, 'role' => 'site_admin']);
    expect(array_column(app(TechRoster::class)->forUser($siteAdmin), 'site'))->toBe(['Sump Pump Gurus']);
});

test('refresh re-sends the invite for a live device and refuses an inactive one; reactivate brings it back with a new code', function (): void {
    Mail::fake();
    $device = TechDevice::factory()->create(['site_id' => $this->gurus->id, 'email' => 'mike@example.com']);
    $roster = app(TechRoster::class);

    $issued = $roster->refresh($device);
    expect($issued['code'])->toMatch('/^\d{6}$/')->and($issued['delivered'])->toBeTrue()
        ->and($issued['link'])->toContain('/capture?device='.$device->id);
    Mail::assertSent(TechCaptureInviteMail::class, fn (TechCaptureInviteMail $m): bool => $m->hasTo('mike@example.com'));

    rosterSignIn($device);
    $roster->revoke($device->fresh());
    expect($roster->refresh($device->fresh()))->toBeNull();

    $back = $roster->reactivate($device->fresh());
    $fresh = $device->fresh();
    expect($back['code'])->toMatch('/^\d{6}$/')
        ->and($fresh->revoked_at)->toBeNull()
        ->and($fresh->token_hash)->toBeNull()   // the old phone session is gone — they sign in again
        ->and(TechRoster::status($fresh))->toBe(TechRoster::INVITED);
});

test('remove deletes an inactive device and its tech account, refuses a live one, and is audited', function (): void {
    $provisioned = app(TechProvisioner::class)->provision($this->gurus->id, 'Leaving Tech');
    $device = $provisioned['device'];
    $userId = $device->user_id;
    $roster = app(TechRoster::class);

    expect($roster->remove($device, $this->admin))->toBeFalse()
        ->and(TechDevice::withoutGlobalScopes()->whereKey($device->id)->exists())->toBeTrue();

    $roster->revoke($device);
    expect($roster->remove($device->fresh(), $this->admin))->toBeTrue()
        ->and(TechDevice::withoutGlobalScopes()->whereKey($device->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($userId)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::TechRemoved->value)->where('target_id', $device->id)->exists())->toBeTrue();
});

test('remove keeps a tech account that still has another device', function (): void {
    $provisioned = app(TechProvisioner::class)->provision($this->gurus->id, 'Two Phones');
    $first = $provisioned['device'];
    TechDevice::factory()->create(['site_id' => $this->gurus->id, 'user_id' => $first->user_id, 'name' => 'Two Phones (spare)']);
    $roster = app(TechRoster::class);
    $roster->revoke($first);

    expect($roster->remove($first->fresh()))->toBeTrue()
        ->and(User::query()->whereKey($first->user_id)->exists())->toBeTrue();
});

test('move rebinds the device to another client the actor may see, is audited, and the phone follows on its next sync', function (): void {
    $device = TechDevice::factory()->create(['site_id' => $this->other->id, 'name' => 'Misfiled Mike']); // landed on the wrong client
    $roster = app(TechRoster::class);

    // The cockpit is locked to a THIRD tenant — the roster still writes across clients.
    CurrentSite::set(Site::factory()->create()->id);
    expect($roster->move($device, $this->gurus, $this->admin))->toBeTrue()
        ->and($device->fresh()->site_id)->toBe($this->gurus->id)
        ->and(AuditLog::query()->where('action', AuditAction::TechMoved->value)->where('target_id', $device->id)->value('metadata'))
        ->toMatchArray(['from_site_id' => $this->other->id, 'to_site_id' => $this->gurus->id]);
    CurrentSite::clear();

    // Moving to the same client is a no-op.
    expect($roster->move($device->fresh(), $this->gurus, $this->admin))->toBeFalse();

    // A Site Admin who cannot see the destination may not move a tech there.
    $account = Account::factory()->create();
    $this->gurus->update(['account_id' => $account->id]);
    $siteAdmin = User::factory()->siteAdmin()->create();
    Membership::create(['user_id' => $siteAdmin->id, 'account_id' => $account->id, 'site_id' => $this->gurus->id, 'role' => 'site_admin']);
    expect($roster->move($device->fresh(), $this->other, $siteAdmin))->toBeFalse()
        ->and($device->fresh()->site_id)->toBe($this->gurus->id);

    // The phone: the API serves the NEW client's vocabulary to the same device token.
    $code = app(DeviceAuthenticator::class)->issueLoginCode($device->fresh());
    $token = app(DeviceAuthenticator::class)->redeemLoginCode($device->fresh(), $code);
    Service::factory()->create(['site_id' => $this->gurus->id, 'name' => 'Sump Pump Replacement']);
    Service::factory()->create(['site_id' => $this->other->id, 'name' => 'Roof Repair']);
    $this->withToken((string) $token)->getJson('/capture/api/options')->assertOk()
        ->assertJsonCount(1, 'job_types')->assertJsonPath('job_types.0.label', 'Sump Pump Replacement');
});

test('a tech account never appears in the Team list, the roster carries the client', function (): void {
    app(TechProvisioner::class)->provision($this->gurus->id, 'Mike R.');
    expect(User::query()->where('role', UserRole::Tech->value)->exists())->toBeTrue();
    $this->actingAs($this->admin);
    $page = new UsersAccess;
    expect(collect($page->getUsersProperty())->pluck('name'))->not->toContain('Mike R.')
        ->and(collect($page->getTechsProperty())->firstWhere('site', 'Sump Pump Gurus')['techs'][0]['name'])->toBe('Mike R.');
});
