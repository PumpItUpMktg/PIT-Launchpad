<?php

use App\Client\LinkOpportunities;
use App\Console\Commands\ReadProspectProfilesCommand;
use App\ContentEngine\Drafting\VoiceResolver;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Enums\UserRole;
use App\Filament\Pages\LinkProspectsPage;
use App\Jobs\ReadProspectProfile;
use App\Jobs\ReadProspectProfiles;
use App\Links\OutreachKitDrafter;
use App\Links\Profile\PageFacts;
use App\Links\Profile\ProspectProfiler;
use App\Models\LinkProspect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ClientHarness;
use Tests\Support\FakeClaudeClient;

const LEAGUE_HTML = <<<'HTML'
<html><head><title>Montclair Little League | Sponsors</title>
<meta property="og:image" content="/images/share.jpg"><link rel="icon" href="/favicon.ico"></head>
<body><script>var x = "junk@script.js";</script>
<h1>Sponsor the 2027 season</h1>
<p>Montclair Little League has run youth baseball in Montclair, NJ since 1954. Our sponsors keep fees low for 600 kids.</p>
<p>Sponsorship chair: Dana Ruiz — <a href="mailto:Sponsors@MontclairLittleLeague.org">sponsors@montclairlittleleague.org</a> · (973) 555-0123</p>
<p>Office: 12 Park St, Montclair, NJ 07042</p>
<a href="/sponsors/packages">See our sponsorship packages</a>
<a href="https://www.facebook.com/montclairll">Facebook</a>
<img src="logo@2x.png">
</body></html>
HTML;

const PACKAGES_HTML = <<<'HTML'
<html><body><h2>Packages</h2>
<p>Gold — $2,500: banner at the main field, logo and link on our website sponsors page, two newsletter mentions, four tickets to opening day.</p>
<p>Silver — $1,000: banner, logo on the website.</p>
<p>Team sponsor — $500: your name on the jersey. Deadline March 1. Questions: treasurer@montclairlittleleague.org</p>
</body></html>
HTML;

const MODEL_JSON = '{"name":"Montclair Little League","overview":"A volunteer-run youth baseball league in Montclair, NJ, serving about 600 children since 1954. Sponsors keep registration fees low.","org_kind":"league","address":"12 Park St, Montclair, NJ 07042","town":"Montclair, NJ","contacts":[{"name":"Dana Ruiz","role":"Sponsorship chair","email":"sponsors@montclairlittleleague.org"}],"offer":{"kind":"sponsorship","levels":[{"name":"Gold","price":"$2,500","includes":["banner at the main field","logo and link on the website sponsors page","two newsletter mentions","four opening-day tickets"]},{"name":"Silver","price":"$1,000","includes":["banner","logo on the website"]},{"name":"Team sponsor","price":"$500","includes":["name on the jersey"]}],"benefits":["signage","website_link","logo_on_site","newsletter_mention","event_tickets","jersey","made_up_benefit"],"website_link":"offered","deadline":"March 1","apply_url":null,"summary":"Three levels from $500 to $2,500; Gold includes a logo and link on the sponsors page plus newsletter mentions."}}';

function profileSite(): Site
{
    return Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com', 'brand_name' => 'Sump Pump Gurus']);
}

function profileProspect(Site $site, array $o = []): LinkProspect
{
    return LinkProspect::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'domain' => 'montclairlittleleague.org', 'kind' => LinkProspectKind::Sponsorship->value, 'status' => LinkProspectStatus::New->value,
        'url' => 'https://www.montclairlittleleague.org/sponsors', 'offices' => ['Montclair, NJ'], 'locality' => 2, 'competitors' => [], 'competitor_count' => 0,
        'first_seen' => '2026-10-01', 'found_at' => now(),
    ], $o));
}

function fakeLeaguePages(): void
{
    Http::fake([
        'https://www.montclairlittleleague.org/sponsors' => Http::response(LEAGUE_HTML),
        'https://www.montclairlittleleague.org/sponsors/packages' => Http::response(PACKAGES_HTML),
        'https://cliftonchamber.org/join' => Http::response('<html><head><title>Clifton Chamber</title></head><body>Join us. membership@cliftonchamber.org</body></html>'),
        'https://down.org/*' => Http::response('', 503),
    ]);
}

it('pulls the facts a page states without a model: title, share image, emails, phones, social links, the next link to follow', function () {
    $f = PageFacts::from(LEAGUE_HTML, 'https://www.montclairlittleleague.org/sponsors');

    expect($f['title'])->toBe('Montclair Little League | Sponsors')
        ->and($f['thumbnail'])->toBe('https://www.montclairlittleleague.org/images/share.jpg')
        ->and($f['emails'])->toBe(['sponsors@montclairlittleleague.org'])          // the script's junk address and the @2x image are not emails
        ->and($f['phones'])->toBe(['(973) 555-0123'])
        ->and($f['social'])->toBe(['facebook' => 'https://www.facebook.com/montclairll'])
        ->and($f['text'])->toContain('youth baseball in Montclair')->not->toContain('var x');
    expect(PageFacts::linkMatching($f['links'], ['sponsor', 'package'], 'https://www.montclairlittleleague.org/sponsors'))->toBe('https://www.montclairlittleleague.org/sponsors/packages');
    // No share image → the icon; apple-touch preferred.
    expect(PageFacts::from('<html><head><link rel="icon" href="/f.ico"><link rel="apple-touch-icon" href="/apple.png"></head></html>', 'https://x.org/')['thumbnail'])->toBe('https://x.org/apple.png');
});

it('profiles the organisation: who, where, contacts, the offer with levels and the website-link answer, the thumbnail — facts from the page win, the model fills the rest', function () {
    fakeLeaguePages();
    $site = profileSite();
    $prospect = profileProspect($site);
    $model = new FakeClaudeClient('Sure: '.MODEL_JSON);

    (new ProspectProfiler($model))->read($prospect);
    $prospect->refresh();
    $pf = $prospect->profile;

    expect($prospect->profile_model_ok)->toBeTrue()->and($prospect->profile_error)->toBeNull()
        ->and($prospect->thumbnail_url)->toBe('https://www.montclairlittleleague.org/images/share.jpg')
        ->and($pf['name'])->toBe('Montclair Little League')
        ->and($pf['overview'])->toContain('volunteer-run youth baseball league')
        ->and($pf['org_kind'])->toBe('league')
        ->and($pf['address'])->toBe('12 Park St, Montclair, NJ 07042')
        ->and($pf['phone'])->toBe('(973) 555-0123')
        ->and($pf['emails'])->toBe(['sponsors@montclairlittleleague.org', 'treasurer@montclairlittleleague.org'])   // both pages, deterministic
        ->and($pf['contacts'])->toBe([['name' => 'Dana Ruiz', 'role' => 'Sponsorship chair', 'email' => 'sponsors@montclairlittleleague.org']])
        ->and($pf['social'])->toBe(['facebook' => 'https://www.facebook.com/montclairll'])
        ->and($pf['pages_read'])->toBe(['https://www.montclairlittleleague.org/sponsors', 'https://www.montclairlittleleague.org/sponsors/packages']);
    $offer = $pf['offer'];
    expect($offer['kind'])->toBe('sponsorship')
        ->and($offer['website_link'])->toBe('offered')
        ->and(array_column($offer['levels'], 'price'))->toBe(['$2,500', '$1,000', '$500'])
        ->and($offer['levels'][0]['includes'])->toContain('logo and link on the website sponsors page')
        ->and($offer['benefits'])->toBe(['website_link', 'logo_on_site', 'newsletter_mention', 'signage', 'jersey', 'event_tickets'])   // the made-up benefit is dropped; order is the fixed list's
        ->and($offer['deadline'])->toBe('March 1');
    expect(ProspectProfiler::offerLine($pf))->toBe('Gold $2,500 · Silver $1,000 · Team sponsor $500 · website link offered');
    // The first address on the page becomes the contact when none was set.
    expect($prospect->contact_email)->toBe('sponsors@montclairlittleleague.org')->and($prospect->contact_source)->toBe('page');
    // The model saw both pages and the facts.
    expect($model->prompts[0])->toContain('PAGE 2 (https://www.montclairlittleleague.org/sponsors/packages)')->toContain('Gold — $2,500')->toContain('sponsors@montclairlittleleague.org');
});

it('keeps the facts and says so when the model does not answer; an unreadable page is recorded, never guessed', function () {
    fakeLeaguePages();
    $site = profileSite();
    $prospect = profileProspect($site, ['contact_email' => 'dana@manual.org', 'contact_source' => 'manual']);

    (new ProspectProfiler(new FakeClaudeClient('I cannot help with that.')))->read($prospect);
    $prospect->refresh();
    expect($prospect->profile_model_ok)->toBeFalse()
        ->and($prospect->profile_error)->toContain('only the facts on the page')
        ->and($prospect->profile['name'])->toBe('Montclair Little League | Sponsors')
        ->and($prospect->profile['overview'])->toBeNull()
        ->and($prospect->profile['emails'])->toBe(['sponsors@montclairlittleleague.org', 'treasurer@montclairlittleleague.org'])
        ->and($prospect->profile['offer']['kind'])->toBe('none')
        ->and($prospect->thumbnail_url)->toBe('https://www.montclairlittleleague.org/images/share.jpg')
        ->and($prospect->contact_email)->toBe('dana@manual.org');      // a hand-entered contact is kept
    expect(ProspectProfiler::offerLine($prospect->profile))->toBeNull();

    $down = profileProspect($site, ['domain' => 'down.org', 'url' => 'https://down.org/sponsors']);
    (new ProspectProfiler(new FakeClaudeClient(MODEL_JSON)))->read($down);
    $down->refresh();
    expect($down->profile)->toBeNull()->and($down->profile_read_at)->not->toBeNull()->and($down->profile_error)->toBe('The page could not be read.');
});

it('a press row is profiled as a PR opportunity: tips address, story form, what they accept, whether articles link out — and the pitch brief asks for coverage, never a link', function () {
    Http::fake([
        'https://montclairlocal.news/' => Http::response('<html><head><title>Montclair Local</title></head><body><a href="/submit-a-story">Submit a story</a><p>Independent nonprofit news for Montclair, NJ. Tips: tips@montclairlocal.news</p></body></html>'),
        'https://montclairlocal.news/submit-a-story' => Http::response('<html><body>Send press releases and community announcements to tips@montclairlocal.news or use the form. Sponsored content available.</body></html>'),
    ]);
    $site = profileSite();
    $prospect = profileProspect($site, ['domain' => 'montclairlocal.news', 'url' => 'https://montclairlocal.news/', 'kind' => LinkProspectKind::Press->value]);
    $model = new FakeClaudeClient('{"name":"Montclair Local","overview":"An independent nonprofit newsroom covering Montclair, NJ: town government, schools, business and weather.","org_kind":"media","address":null,"town":"Montclair, NJ","contacts":[{"name":"Sam Lee","role":"Editor","email":null}],"press":{"is_press":true,"coverage_area":"Montclair, NJ","tips_email":"Tips@MontclairLocal.news","submit_url":"https://montclairlocal.news/submit-a-story","press_releases":true,"announcements":true,"sponsored_content":true,"links_out":true,"beats":["government","schools","business","weather"]},"offer":{"kind":"none","levels":[],"benefits":[],"website_link":"not_mentioned","deadline":null,"apply_url":null,"summary":null}}');

    (new ProspectProfiler($model))->read($prospect);
    $prospect->refresh();
    expect($model->prompts[0])->toContain('local news outlet')->toContain('PR opportunity')->toContain('PAGE 2 (https://montclairlocal.news/submit-a-story)');
    $pr = $prospect->profile['press'];
    expect($pr['is_press'])->toBeTrue()
        ->and($pr['tips_email'])->toBe('tips@montclairlocal.news')
        ->and($pr['submit_url'])->toBe('https://montclairlocal.news/submit-a-story')
        ->and($pr['press_releases'])->toBeTrue()->and($pr['links_out'])->toBeTrue()
        ->and($pr['beats'])->toBe(['government', 'schools', 'business', 'weather']);
    expect(ProspectProfiler::offerLine($prospect->profile))->toBe('PR: news tips: tips@montclairlocal.news · press releases accepted · community announcements · sponsored posts sold · articles link out');
    expect($prospect->contact_email)->toBe('tips@montclairlocal.news');

    // The kit brief for a press row asks for coverage and offers the business as a local source — never a link.
    $drafter = new OutreachKitDrafter($kitModel = new FakeClaudeClient('{"short_description":"s","long_description":"l","categories":[],"pitch_subject":"A local source for storm season","pitch_body":"Hello"}'), app(VoiceResolver::class));
    $drafter->forProspect($prospect, null);
    expect($kitModel->prompts[0])->toContain('LOCAL SOURCE')->toContain('never asking for a link');
    expect(LinkOpportunities::explain(LinkProspectKind::Press, $site))->toContain('PR opportunity');
});

it('the batch reads unread lane rows only, most local first, bounded, one lane when asked; the command reports each', function () {
    fakeLeaguePages();
    $site = profileSite();
    app()->instance(ProspectProfiler::class, new ProspectProfiler(new FakeClaudeClient(MODEL_JSON)));   // the contextual binding outranks a plain instance of the client
    $league = profileProspect($site);
    $chamber = profileProspect($site, ['domain' => 'cliftonchamber.org', 'url' => 'https://cliftonchamber.org/join', 'kind' => LinkProspectKind::Membership->value, 'locality' => 3]);
    profileProspect($site, ['domain' => 'done.org', 'profile_read_at' => now(), 'profile' => ['name' => 'Done']]);
    profileProspect($site, ['domain' => 'njhomeblog.com', 'kind' => LinkProspectKind::Local->value, 'url' => null]);   // not a lane: never profiled here
    profileProspect($site, ['domain' => 'won.org', 'status' => LinkProspectStatus::Acquired->value]);

    // The batch job works in chunks and re-dispatches itself for the rest, never past the queue's retry window.
    Queue::fake();
    foreach (range(1, 12) as $i) {
        profileProspect($site, ['domain' => "org{$i}.org", 'url' => 'https://cliftonchamber.org/join', 'locality' => 0]);
    }
    (new ReadProspectProfiles($site->id, null, 40))->handle(app(ProspectProfiler::class));
    expect(LinkProspect::withoutGlobalScope(SiteScope::class)->whereNotNull('profile_read_at')->count())->toBe(1 + ReadProspectProfiles::CHUNK);   // "done.org" + one chunk
    Queue::assertPushed(ReadProspectProfiles::class, fn (ReadProspectProfiles $j) => $j->siteId === $site->id && $j->limit === 40 - ReadProspectProfiles::CHUNK);
    LinkProspect::withoutGlobalScope(SiteScope::class)->where('domain', 'like', 'org%')->delete();
    LinkProspect::withoutGlobalScope(SiteScope::class)->whereIn('domain', ['cliftonchamber.org', 'montclairlittleleague.org'])->update(['profile_read_at' => null, 'profile' => null]);

    expect(array_map(fn ($p) => $p->domain, ReadProspectProfiles::pending($site->id, null, 40)))->toBe(['cliftonchamber.org', 'montclairlittleleague.org'])
        ->and(array_map(fn ($p) => $p->domain, ReadProspectProfiles::pending($site->id, 'sponsorship', 40)))->toBe(['montclairlittleleague.org'])
        ->and(ReadProspectProfiles::pending($site->id, null, 1))->toHaveCount(1);

    $this->artisan(ReadProspectProfilesCommand::class, ['--site' => $site->id])
        ->expectsOutputToContain('Sump Pump Gurus — reading 2 profile(s):')
        ->expectsOutputToContain('Done: 2 profiled, 0 facts-only (model did not answer), 0 unreadable.')
        ->assertSuccessful();
    expect($league->fresh()->profile_read_at)->not->toBeNull()->and($chamber->fresh()->profile_read_at)->not->toBeNull();
    $this->artisan(ReadProspectProfilesCommand::class, ['--site' => $site->id])->expectsOutputToContain('nothing unread')->assertSuccessful();
});

it('the Link prospects page shows the organisation and its offer on the row and in the panel, picks a named contact, and queues reads', function () {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = profileSite();
    app(ActiveTenant::class)->set((string) $site->id);
    $profile = json_decode(MODEL_JSON, true);
    $profile['emails'] = ['sponsors@montclairlittleleague.org'];
    $profile['social'] = [];
    $profile['pages_read'] = ['https://www.montclairlittleleague.org/sponsors'];
    $league = profileProspect($site, ['profile' => $profile, 'profile_read_at' => now(), 'profile_model_ok' => true, 'thumbnail_url' => 'https://www.montclairlittleleague.org/images/share.jpg']);
    profileProspect($site, ['domain' => 'unread.org', 'url' => 'https://unread.org/sponsors']);

    Livewire::test(LinkProspectsPage::class)
        ->assertSee('Montclair Little League')                                                 // the card's top rows
        ->assertSee('Gold $2,500 · Silver $1,000 · Team sponsor $500 · website link offered')
        ->assertSee('https://www.montclairlittleleague.org/images/share.jpg')
        ->call('openOffice', 'corporate')                                                      // no office behind the fixture → the corporate area
        ->assertSee('volunteer-run youth baseball league')
        ->assertSee('12 Park St, Montclair, NJ 07042')
        ->assertSee('Dana Ruiz')
        ->assertSee('Read profiles')
        ->call('readProfiles')
        ->call('openKit', $league->id)
        ->assertSee('Dana Ruiz')->assertSee('Sponsorship chair')->assertSee('12 Park St, Montclair, NJ 07042')->assertSee('Deadline: March 1')
        ->call('pickContact', 'sponsors@montclairlittleleague.org')
        ->call('readProfile');
    expect($league->fresh()->contact_email)->toBe('sponsors@montclairlittleleague.org')->and($league->fresh()->contact_source)->toBe('manual');
    Queue::assertPushed(ReadProspectProfiles::class, fn (ReadProspectProfiles $j) => $j->siteId === $site->id && $j->kind === null);
    Queue::assertPushed(ReadProspectProfile::class, fn (ReadProspectProfile $j) => $j->prospectId === $league->id);
});

it('the client portal shows the organisation, its overview, the thumbnail and what you get', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);
    $profile = json_decode(MODEL_JSON, true) + ['emails' => [], 'social' => [], 'pages_read' => []];
    profileProspect($site, ['profile' => $profile, 'profile_read_at' => now(), 'profile_model_ok' => true, 'thumbnail_url' => 'https://www.montclairlittleleague.org/images/share.jpg', 'client_decision' => null]);

    $row = app(LinkOpportunities::class)->for($site)['to_review'][0];
    expect($row['name'])->toBe('Montclair Little League')
        ->and($row['offer_line'])->toBe('Gold $2,500 · Silver $1,000 · Team sponsor $500 · website link offered')
        ->and($row['website_link'])->toBe('offered')
        ->and($row['thumbnail_url'])->toBe('https://www.montclairlittleleague.org/images/share.jpg');
    Livewire::test(App\Filament\Client\Pages\LinkOpportunities::class)
        ->assertSee('Montclair Little League')
        ->assertSee('What you get:')
        ->assertSee('Three levels from $500 to $2,500')
        ->assertSee('https://www.montclairlittleleague.org/images/share.jpg');
    // Honest: the client never sees the raw emails or contact names — those are the operator's.
    expect(json_encode($row))->not->toContain('Dana Ruiz');
});
