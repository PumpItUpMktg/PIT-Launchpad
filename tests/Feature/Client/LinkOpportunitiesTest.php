<?php

use App\Client\LinkOpportunities;
use App\Enums\ClientDecision;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Filament\Client\Pages\LinkOpportunities as LinkOpportunitiesPage;
use App\Links\Outreach\OutreachGuard;
use App\Models\LinkProspect;
use App\Models\OutreachEmail;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\ClientHarness;

function loProspect(Site $site, string $domain, array $overrides = []): LinkProspect
{
    return LinkProspect::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'domain' => $domain, 'kind' => LinkProspectKind::Sponsorship->value, 'status' => LinkProspectStatus::New->value,
        'url' => "https://{$domain}/sponsors", 'offices' => ['Montclair, NJ'], 'locality' => 2, 'competitors' => [], 'competitor_count' => 0,
        'first_seen' => '2026-10-01', 'found_at' => now(),
    ], $overrides));
}

it('shows the client every open opportunity in plain words, split by their decision, with the money named where there is some', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    $site->forceFill(['sponsorship_budget_min' => 500, 'sponsorship_budget_max' => 3000, 'outreach_monthly_cap' => 10])->save();
    loProspect($site, 'montclairlittleleague.org');
    loProspect($site, 'cliftonchamber.org', ['kind' => LinkProspectKind::Membership->value, 'offices' => ['Clifton, NJ'], 'locality' => 3]);
    loProspect($site, 'zoeller.com', ['kind' => LinkProspectKind::DealerLocator->value, 'offices' => ['NJ'], 'locality' => 1]);
    loProspect($site, 'njhomeblog.com', ['kind' => LinkProspectKind::Local->value, 'url' => null, 'offices' => null, 'locality' => 0, 'competitors' => ['rival.com'], 'competitor_count' => 1]);
    $yes = loProspect($site, 'rotary.org', ['client_decision' => ClientDecision::Interested->value, 'client_decided_at' => now(), 'status' => LinkProspectStatus::Contacted->value, 'emails_sent' => 1, 'last_emailed_at' => now()->subDay()]);
    loProspect($site, 'won.org', ['client_decision' => ClientDecision::Interested->value, 'client_decided_at' => now(), 'status' => LinkProspectStatus::Acquired->value, 'emails_sent' => 2]);
    loProspect($site, 'no.org', ['client_decision' => ClientDecision::Pass->value, 'client_decided_at' => now()]);
    loProspect($site, 'gone.org', ['status' => LinkProspectStatus::Dismissed->value]);          // the operator set it aside — the client never sees it
    OutreachEmail::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'link_prospect_id' => $yes->id, 'kind' => OutreachEmail::PITCH, 'to_email' => 'a@rotary.org',
        'from_email' => 'o@x.com', 'subject' => 's', 'body' => 'b', 'status' => OutreachEmail::SENT, 'approved_at' => now()]);

    $board = app(LinkOpportunities::class)->for($site->fresh());

    expect(array_column($board['to_review'], 'domain'))->toBe(['cliftonchamber.org', 'montclairlittleleague.org', 'zoeller.com', 'njhomeblog.com'])   // most local first
        ->and(array_column($board['interested'], 'domain'))->toBe(['rotary.org', 'won.org'])
        ->and(array_column($board['passed'], 'domain'))->toBe(['no.org'])
        ->and($board['totals'])->toBe(['to_review' => 4, 'interested' => 2, 'passed' => 1, 'contacted' => 2, 'won' => 1])
        ->and($board['outreach'])->toBe(['used' => 1, 'cap' => 10]);

    $league = $board['to_review'][1];
    expect($league['what'])->toContain('A local organisation that takes sponsors')->toContain('Your sponsorship range on file is $500–$3,000 a year.')
        ->and($league['near'])->toBe('Montclair, NJ')
        ->and($league['url'])->toBe('https://montclairlittleleague.org/sponsors')
        ->and($league['status_text'])->toBe('Waiting for your decision');
    expect($board['to_review'][2]['what'])->toContain('normally free for businesses that carry the brand');
    expect($board['to_review'][3]['near'])->toBeNull()->and($board['to_review'][3]['url'])->toBe('https://njhomeblog.com');
    expect($board['interested'][0]['status_text'])->toStartWith('We wrote to them ')
        ->and($board['interested'][1]['status_text'])->toBe('Done — they link to you');

    // Honest framing: nothing on the board promises a ranking.
    $text = strtolower(json_encode($board, JSON_THROW_ON_ERROR));
    expect($text)->not->toContain('guarantee')->not->toContain('rank #1')->not->toContain('first page');
});

it('the client decides — Interested, Pass, or back to undecided — only on their own site\'s open prospects', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    ['site' => $other] = ClientHarness::make();
    $mine = loProspect($site, 'montclairlittleleague.org');
    $theirs = loProspect($other, 'cliftonchamber.org');
    $closed = loProspect($site, 'won.org', ['status' => LinkProspectStatus::Acquired->value]);
    $svc = app(LinkOpportunities::class);

    expect($svc->decide($site, $client, $mine->id, ClientDecision::Interested))->toBeTrue();
    $mine->refresh();
    expect($mine->client_decision)->toBe(ClientDecision::Interested)->and($mine->client_decided_by)->toBe($client->id)->and($mine->client_decided_at)->not->toBeNull();
    // Now the guard's client line is green for this prospect.
    $site->forceFill(['domain_url' => 'https://example-client.com', 'outreach_from_email' => 'hi@example-client.com'])->save();
    $mine->forceFill(['contact_email' => 'a@b.org'])->save();
    expect(app(OutreachGuard::class)->check($site->fresh(), $mine->fresh(), OutreachEmail::PITCH)->ok())->toBeTrue();

    expect($svc->decide($site, $client, $mine->id, null))->toBeTrue();
    expect($mine->fresh()->client_decision)->toBeNull()->and($mine->fresh()->client_decided_by)->toBeNull();

    expect($svc->decide($site, $client, $theirs->id, ClientDecision::Interested))->toBeFalse();   // another account's prospect: not found under this site
    expect($theirs->fresh()->client_decision)->toBeNull();
    expect($svc->decide($site, $client, $closed->id, ClientDecision::Pass))->toBeFalse();          // a closed prospect is history
});

it('the portal page lists what is waiting, records a click, and never shows another account\'s opportunities', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make(['brand_name' => 'Apex Marketing']);
    ['site' => $other] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);
    $league = loProspect($site, 'montclairlittleleague.org');
    loProspect($other, 'cliftonchamber.org');

    Livewire::test(LinkOpportunitiesPage::class)
        ->assertOk()
        ->assertSee('Local organisations that could link to you')
        ->assertSee('montclairlittleleague.org')
        ->assertDontSee('cliftonchamber.org')
        ->assertSee('Interested')
        ->call('interested', $league->id)
        ->assertSee('Nothing waiting for you')
        ->call('setShow', 'interested')
        ->assertSee('montclairlittleleague.org')
        ->assertSee('Not written to yet')
        ->assertSee('Change my mind')
        ->call('undo', $league->id)
        ->call('setShow', 'review')
        ->assertSee('montclairlittleleague.org')
        ->call('pass', $league->id)
        ->call('setShow', 'passed')
        ->assertSee('montclairlittleleague.org');
    expect($league->fresh()->client_decision)->toBe(ClientDecision::Pass)->and($league->fresh()->client_decided_by)->toBe($client->id);
});
