<?php

use App\Enums\ClientDecision;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Enums\UserRole;
use App\Filament\Pages\LinkProspectsPage;
use App\Jobs\FindProspectContact;
use App\Jobs\SendOutreachEmail;
use App\Links\Outreach\ContactFinder;
use App\Links\Outreach\FollowUpComposer;
use App\Links\Outreach\OutreachGuard;
use App\Links\Outreach\OutreachRefused;
use App\Links\Outreach\OutreachSender;
use App\Links\Outreach\Sender;
use App\Mail\OutreachMail;
use App\Models\LinkProspect;
use App\Models\OutreachEmail;
use App\Models\OutreachKit;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function outreachSite(array $overrides = []): Site
{
    return Site::factory()->create(array_merge([
        'domain_url' => 'https://sumppumpgurus.com', 'brand_name' => 'Sump Pump Gurus', 'phone' => '(973) 555-0100',
        'corporate_street' => '10 Main St', 'corporate_city' => 'Clifton', 'corporate_state' => 'NJ', 'corporate_postal_code' => '07011',
        'outreach_from_email' => 'outreach@sumppumpgurus.com', 'outreach_from_name' => 'Eric at Sump Pump Gurus', 'outreach_monthly_cap' => 3,
    ], $overrides));
}

function outreachProspect(Site $site, array $overrides = []): LinkProspect
{
    return LinkProspect::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'domain' => 'montclairlittleleague.org', 'kind' => LinkProspectKind::Sponsorship->value, 'status' => LinkProspectStatus::New->value,
        'url' => 'https://www.montclairlittleleague.org/sponsors', 'offices' => ['Montclair, NJ'], 'locality' => 2, 'competitors' => [], 'competitor_count' => 0,
        'first_seen' => '2026-10-01', 'found_at' => now(), 'contact_email' => 'sponsors@montclairlittleleague.org', 'contact_source' => 'mailto',
        'client_decision' => ClientDecision::Interested->value, 'client_decided_at' => now(),
    ], $overrides));
}

function outreachKit(Site $site, LinkProspect $prospect): OutreachKit
{
    return OutreachKit::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'location_id' => null, 'target_kind' => OutreachKit::PROSPECT, 'target_domain' => $prospect->domain,
        'payload' => ['pitch_subject' => 'Sponsoring the 2027 season', 'pitch_body' => "Hello,\n\nWe would like to hear about your sponsorship packages.\n\nThank you,\nEric", 'link_url' => 'https://sumppumpgurus.com/'],
        'model' => 'scoring', 'model_ok' => true, 'drafted_at' => now(),
    ]);
}

it('mail goes out from the client\'s own domain only — any other sender is nothing', function () {
    $site = outreachSite();
    expect(Sender::for($site))->toBe(['email' => 'outreach@sumppumpgurus.com', 'name' => 'Eric at Sump Pump Gurus']);
    expect(Sender::onOwnDomain($site, 'Eric@Mail.SumpPumpGurus.com'))->toBeTrue()
        ->and(Sender::onOwnDomain($site, 'eric@gmail.com'))->toBeFalse()
        ->and(Sender::onOwnDomain($site, 'eric@notsumppumpgurus.com'))->toBeFalse()
        ->and(Sender::onOwnDomain($site, 'not an email'))->toBeFalse();

    $site->forceFill(['outreach_from_email' => 'eric@gmail.com'])->save();
    expect(Sender::for($site->fresh()))->toBeNull();
    // Even a stored on-domain sender stops working the moment the site's domain changes.
    $site->forceFill(['outreach_from_email' => 'outreach@sumppumpgurus.com', 'domain_url' => 'https://pumpgurus.co'])->save();
    expect(Sender::for($site->fresh()))->toBeNull();
});

it('the guard is red until every line is green: switched on, own-domain sender, client Interested, contact, open, cap, once', function () {
    $site = outreachSite();
    $prospect = outreachProspect($site);
    $guard = app(OutreachGuard::class);
    expect($guard->check($site, $prospect, OutreachEmail::PITCH)->ok())->toBeTrue();

    config()->set('launchpad.links.outreach.enabled', false);
    expect($guard->check($site, $prospect, OutreachEmail::PITCH)->reasons())->toBe(['Outreach is switched off for the platform.']);
    config()->set('launchpad.links.outreach.enabled', true);

    $site->forceFill(['outreach_from_email' => 'eric@gmail.com'])->save();
    expect($guard->check($site->fresh(), $prospect, OutreachEmail::PITCH)->reasons()[0])->toContain('No sender on the tenant\'s own domain');
    $site->forceFill(['outreach_from_email' => 'outreach@sumppumpgurus.com'])->save();

    $prospect->forceFill(['client_decision' => null])->save();
    expect($guard->check($site->fresh(), $prospect->fresh(), OutreachEmail::PITCH)->reasons())->toBe(['The client has not marked this prospect Interested.']);
    $prospect->forceFill(['client_decision' => ClientDecision::Pass])->save();
    expect($guard->check($site->fresh(), $prospect->fresh(), OutreachEmail::PITCH)->ok())->toBeFalse();
    $prospect->forceFill(['client_decision' => ClientDecision::Interested])->save();

    $prospect->forceFill(['contact_email' => null])->save();
    expect($guard->check($site->fresh(), $prospect->fresh(), OutreachEmail::PITCH)->reasons())->toBe(['No contact address — find one or enter it.']);
    $prospect->forceFill(['contact_email' => 'sponsors@montclairlittleleague.org'])->save();

    foreach ([LinkProspectStatus::Acquired, LinkProspectStatus::Declined, LinkProspectStatus::Dismissed] as $closed) {
        $prospect->forceFill(['status' => $closed])->save();
        expect($guard->check($site->fresh(), $prospect->fresh(), OutreachEmail::PITCH)->reasons())->toBe(['The prospect is closed ('.$closed->label().').']);
    }
    $prospect->forceFill(['status' => LinkProspectStatus::Replied])->save();
    expect($guard->check($site->fresh(), $prospect->fresh(), OutreachEmail::PITCH)->ok())->toBeTrue();
});

it('the monthly cap counts approved emails (queued and sent, not failed) in the calendar month and never exceeds the platform ceiling', function () {
    Carbon::setTestNow('2026-10-22 15:00:00');
    $site = outreachSite(['outreach_monthly_cap' => 3]);
    $guard = app(OutreachGuard::class);
    $mk = function (string $status, string $when) use ($site): void {
        $p = outreachProspect($site, ['domain' => 'd'.uniqid().'.org']);
        OutreachEmail::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'link_prospect_id' => $p->id, 'kind' => OutreachEmail::PITCH, 'to_email' => 'a@b.org',
            'from_email' => 'outreach@sumppumpgurus.com', 'subject' => 's', 'body' => 'b', 'status' => $status, 'approved_at' => Carbon::parse($when)]);
    };
    $mk(OutreachEmail::SENT, '2026-10-02');
    $mk(OutreachEmail::QUEUED, '2026-10-20');
    $mk(OutreachEmail::FAILED, '2026-10-21');        // the provider refused it — does not count
    $mk(OutreachEmail::SENT, '2026-09-30');          // last month
    expect($guard->usedThisMonth($site))->toBe(2);
    $prospect = outreachProspect($site, ['domain' => 'fresh.org']);
    expect($guard->check($site, $prospect, OutreachEmail::PITCH)->ok())->toBeTrue();
    $mk(OutreachEmail::SENT, '2026-10-22');
    expect($guard->check($site, $prospect, OutreachEmail::PITCH)->reasons())->toBe(['The monthly cap is used up (3 of 3 this month).']);
    Carbon::setTestNow('2026-11-01 09:00:00');
    expect($guard->check($site, $prospect, OutreachEmail::PITCH)->ok())->toBeTrue();   // a new month

    // A tenant cap above the platform ceiling is the ceiling; a missing cap is the ceiling.
    expect(OutreachGuard::capFor(outreachSite(['outreach_monthly_cap' => 500])))->toBe(20)
        ->and(OutreachGuard::capFor(outreachSite(['outreach_monthly_cap' => 7])))->toBe(7);
    config()->set('launchpad.links.outreach.hard_cap', 5);
    expect(OutreachGuard::capFor(outreachSite(['outreach_monthly_cap' => 7])))->toBe(5);
    Carbon::setTestNow();
});

it('Approve & send writes the approved row, marks the prospect contacted, queues the one send — and refuses a second pitch', function () {
    Queue::fake();
    $site = outreachSite();
    $prospect = outreachProspect($site);
    $kit = outreachKit($site, $prospect);
    $by = User::factory()->create(['role' => UserRole::Operator]);

    $email = app(OutreachSender::class)->approve($site, $prospect, OutreachEmail::PITCH, '  Sponsoring the  2027 season ', "Hello,\r\n\r\nEdited by hand.\r\n", $kit, $by);
    expect($email->status)->toBe(OutreachEmail::QUEUED)
        ->and($email->subject)->toBe('Sponsoring the 2027 season')
        ->and($email->body)->toBe("Hello,\n\nEdited by hand.")
        ->and($email->to_email)->toBe('sponsors@montclairlittleleague.org')
        ->and($email->from_email)->toBe('outreach@sumppumpgurus.com')
        ->and($email->from_name)->toBe('Eric at Sump Pump Gurus')
        ->and($email->outreach_kit_id)->toBe($kit->id)
        ->and($email->sent_by)->toBe($by->id);
    expect($prospect->fresh()->status)->toBe(LinkProspectStatus::Contacted);
    Queue::assertPushed(SendOutreachEmail::class, fn (SendOutreachEmail $j) => $j->emailId === $email->id);

    expect(fn () => app(OutreachSender::class)->approve($site, $prospect->fresh(), OutreachEmail::PITCH, 'again', 'again', $kit, $by))
        ->toThrow(OutreachRefused::class, 'The pitch has already been sent');
    expect(fn () => app(OutreachSender::class)->approve($site, $prospect->fresh(), OutreachEmail::PITCH, '', 'x', $kit, $by))
        ->toThrow(OutreachRefused::class, 'subject and the email body');
    expect(OutreachEmail::withoutGlobalScope(SiteScope::class)->count())->toBe(1);
});

it('the worker sends the email from the client\'s address with replies to the same inbox, the body verbatim, the postal address and a way out', function () {
    Mail::fake();
    $site = outreachSite();
    $prospect = outreachProspect($site);
    $email = OutreachEmail::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'link_prospect_id' => $prospect->id, 'kind' => OutreachEmail::PITCH,
        'to_email' => 'sponsors@montclairlittleleague.org', 'from_email' => 'outreach@sumppumpgurus.com', 'from_name' => 'Eric at Sump Pump Gurus',
        'subject' => 'Sponsoring the 2027 season', 'body' => "Hello,\n\nWe would like to hear about your packages.\n\nThank you,\nEric", 'status' => OutreachEmail::QUEUED, 'approved_at' => now()]);

    (new SendOutreachEmail($email->id))->handle();

    Mail::assertSent(OutreachMail::class, function (OutreachMail $m) {
        $m->assertHasTo('sponsors@montclairlittleleague.org');
        $m->assertFrom('outreach@sumppumpgurus.com', 'Eric at Sump Pump Gurus');
        $m->assertHasReplyTo('outreach@sumppumpgurus.com');
        $m->assertHasSubject('Sponsoring the 2027 season');
        $text = $m->render();
        expect($text)->toContain('We would like to hear about your packages.')
            ->toContain('10 Main St, Clifton, NJ 07011')
            ->toContain('(973) 555-0100')
            ->toContain('https://sumppumpgurus.com')
            ->toContain('reply with "no thanks" and we won\'t write again');

        return true;
    });
    $email->refresh();
    expect($email->status)->toBe(OutreachEmail::SENT)->and($email->sent_at)->not->toBeNull();
    $prospect->refresh();
    expect($prospect->emails_sent)->toBe(1)->and($prospect->last_emailed_at)->not->toBeNull();

    // The worker re-checks the domain rule at the last moment: a row whose sender drifted off-domain fails, never sends.
    $site->forceFill(['domain_url' => 'https://elsewhere.com'])->save();
    $second = OutreachEmail::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'link_prospect_id' => $prospect->id, 'kind' => OutreachEmail::FOLLOW_UP,
        'to_email' => 'sponsors@montclairlittleleague.org', 'from_email' => 'outreach@sumppumpgurus.com', 'subject' => 'Re', 'body' => 'b', 'status' => OutreachEmail::QUEUED, 'approved_at' => now()]);
    (new SendOutreachEmail($second->id))->handle();
    expect($second->fresh()->status)->toBe(OutreachEmail::FAILED)->and($second->fresh()->error)->toContain('not on the tenant\'s own domain');
    Mail::assertSent(OutreachMail::class, 1);
});

it('one follow-up, only after the pitch, only after the waiting period, never twice', function () {
    Carbon::setTestNow('2026-10-22 15:00:00');
    Queue::fake();
    $site = outreachSite();
    $prospect = outreachProspect($site);
    $guard = app(OutreachGuard::class);
    expect($guard->check($site, $prospect, OutreachEmail::FOLLOW_UP)->reasons())->toContain('No pitch has been sent yet.');

    $pitch = app(OutreachSender::class)->approve($site, $prospect, OutreachEmail::PITCH, 'Sponsoring the 2027 season', 'Hello', null, null);
    expect($guard->check($site, $prospect->fresh(), OutreachEmail::FOLLOW_UP)->reasons())->toBe(['Give the pitch 5 days — a follow-up may go from Oct 27, 2026.']);

    Carbon::setTestNow('2026-10-27 15:00:00');
    $draft = app(FollowUpComposer::class)->compose($site, $pitch);
    expect($draft['subject'])->toBe('Re: Sponsoring the 2027 season')
        ->and($draft['body'])->toContain('I wrote on Oct 22, 2026 about Sump Pump Gurus')
        ->and($draft['body'])->toContain('I will not write again')
        ->and($draft['body'])->toEndWith('Eric at Sump Pump Gurus');
    $follow = app(OutreachSender::class)->approve($site, $prospect->fresh(), OutreachEmail::FOLLOW_UP, $draft['subject'], $draft['body'], null, null);
    expect($follow->kind)->toBe(OutreachEmail::FOLLOW_UP);
    expect($guard->check($site, $prospect->fresh(), OutreachEmail::FOLLOW_UP)->reasons())->toBe(['The one follow-up has already been sent.']);
    Carbon::setTestNow();
});

it('the contact finder takes a mailto on the page, then an address on the contact page, own-domain and role first; a hand-entered address is kept', function () {
    $site = outreachSite();
    Http::fake([
        'https://www.montclairlittleleague.org/sponsors' => Http::response('<html><a href="/contact-us">Contact us</a><img src="logo@2x.png"><p>Email <a href="mailto:Sponsors@MontclairLittleLeague.org?subject=hi">us</a> or webmaster@wixpress.com or jane@gmail.com</p></html>'),
        'https://www.montclairlittleleague.org/contact-us' => Http::response('<html>info@montclairlittleleague.org</html>'),
        'https://cliftonchamber.org/join' => Http::response('<html><a href="https://cliftonchamber.org/contact">Contact</a> nothing here</html>'),
        'https://cliftonchamber.org/contact' => Http::response('<html>Write to membership@cliftonchamber.org or president@cliftonchamber.org</html>'),
        'https://nothing.org/' => Http::response('<html>no addresses, no contact link</html>'),
    ]);
    $finder = app(ContactFinder::class);

    $league = outreachProspect($site, ['contact_email' => null, 'contact_source' => null]);
    expect($finder->find($league))->toBe(['email' => 'sponsors@montclairlittleleague.org', 'source' => 'mailto', 'candidates' => ['sponsors@montclairlittleleague.org', 'jane@gmail.com']]);

    $chamber = outreachProspect($site, ['domain' => 'cliftonchamber.org', 'url' => 'https://cliftonchamber.org/join', 'contact_email' => null, 'contact_source' => null]);
    expect($finder->find($chamber))->toBe(['email' => 'membership@cliftonchamber.org', 'source' => 'page', 'candidates' => ['membership@cliftonchamber.org', 'president@cliftonchamber.org']]);

    $none = outreachProspect($site, ['domain' => 'nothing.org', 'url' => null, 'contact_email' => null, 'contact_source' => null]);
    expect($finder->find($none))->toBe(['email' => null, 'source' => null, 'candidates' => []]);

    (new FindProspectContact($chamber->id))->handle($finder);
    $chamber->refresh();
    expect($chamber->contact_email)->toBe('membership@cliftonchamber.org')->and($chamber->contact_source)->toBe('page')->and($chamber->contact_checked_at)->not->toBeNull();

    $manual = outreachProspect($site, ['domain' => 'manual.org', 'url' => 'https://cliftonchamber.org/join', 'contact_email' => 'dana@manual.org', 'contact_source' => 'manual']);
    (new FindProspectContact($manual->id))->handle($finder);
    expect($manual->fresh()->contact_email)->toBe('dana@manual.org')->and($manual->fresh()->contact_candidates)->toBe(['membership@cliftonchamber.org', 'president@cliftonchamber.org']);
});

it('the Link prospects page records the client decision, pre-fills the send box from the kit, and Approve & send queues one email', function () {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = outreachSite();
    app(ActiveTenant::class)->set((string) $site->id);
    $prospect = outreachProspect($site, ['client_decision' => null, 'client_decided_at' => null]);
    outreachKit($site, $prospect);

    $page = Livewire::test(LinkProspectsPage::class)
        ->assertSee('Outreach this month')->assertSee('0 of 3')->assertSee('outreach@sumppumpgurus.com')
        ->call('setDecision', $prospect->id, 'interested');
    expect($prospect->fresh()->client_decision)->toBe(ClientDecision::Interested)->and($prospect->fresh()->client_decided_by)->not->toBeNull();

    $page->call('openKit', $prospect->id)
        ->assertSet('sendKind', OutreachEmail::PITCH)
        ->assertSet('sendSubject', 'Sponsoring the 2027 season')
        ->assertSee('Approve')
        ->set('sendBody', "Hello,\n\nEdited in the box.")
        ->call('approveAndSend')
        ->assertSet('sendKind', OutreachEmail::FOLLOW_UP);   // the box moves on to the one follow-up
    $email = OutreachEmail::withoutGlobalScope(SiteScope::class)->sole();
    expect($email->body)->toBe("Hello,\n\nEdited in the box.")->and($email->status)->toBe(OutreachEmail::QUEUED);
    Queue::assertPushed(SendOutreachEmail::class, 1);

    // Pass closes the door: the guard goes red and a click is refused.
    $page->call('setDecision', $prospect->id, 'pass')->call('openKit', $prospect->id)->call('approveAndSend');
    expect(OutreachEmail::withoutGlobalScope(SiteScope::class)->count())->toBe(1);
    Queue::assertPushed(SendOutreachEmail::class, 1);
});
