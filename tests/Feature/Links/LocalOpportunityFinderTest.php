<?php

use App\Console\Commands\FindLocalOpportunitiesCommand;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Pages\LinkProspectsPage;
use App\Integrations\Backlinks\BacklinkPage;
use App\Integrations\Backlinks\BacklinkProvider;
use App\Integrations\Backlinks\BacklinkSummary;
use App\Integrations\Census\County;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\Jobs\FindLocalOpportunities;
use App\Links\LocalOpportunityFinder;
use App\Links\LocalOpportunityQueries;
use App\Models\LinkProspect;
use App\Models\LinkProspectRun;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function opportunitySite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com', 'brand_name' => 'Sump Pump Gurus', 'brands' => ['Zoeller']]);
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'trade' => 'sump pump']);
    $components = fn (string $city, string $st) => [
        ['long_name' => $city, 'short_name' => $city, 'types' => ['locality', 'political']],
        ['long_name' => 'New Jersey', 'short_name' => $st, 'types' => ['administrative_area_level_1', 'political']],
    ];
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office', 'address_components' => $components('Montclair', 'NJ'), 'home_county_geoid' => '34013', 'county_geoids' => []]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Clifton office', 'address_components' => $components('Clifton', 'NJ'), 'home_county_geoid' => '34031', 'county_geoids' => []]);
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(
        counties: [new County('34013', 'Essex County', '34', '013'), new County('34031', 'Passaic County', '34', '031')],
    ));

    return $site;
}

function opportunityBacklinks(array $ranks, array &$asked): BacklinkProvider
{
    return new class($ranks, $asked) implements BacklinkProvider
    {
        public function __construct(private array $ranks, public array &$asked) {}

        public function summary(string $target): BacklinkSummary
        {
            $this->asked[] = $target;

            return new BacklinkSummary(0, 0, 0, $this->ranks[$target] ?? null);
        }

        public function referringDomains(string $target, int $limit = 1000, ?string $after = null): BacklinkPage
        {
            return new BacklinkPage([], 0, null);
        }

        public function domainIntersection(array $targets, array $exclude, int $limit = 300): array
        {
            return [];
        }
    };
}

it('builds one de-duplicated search plan per lane from the offices, their counties, the trade and the brands', function () {
    $site = opportunitySite();
    $plan = app(LocalOpportunityQueries::class)->for($site);
    $queries = array_column($plan, 'query');

    expect($queries)->toContain('Montclair NJ sponsorship opportunities')
        ->toContain('Essex County NJ community events sponsors')
        ->toContain('Clifton NJ chamber of commerce members')
        ->toContain('Passaic County NJ home builders association members')
        ->toContain('Essex County NJ workforce development employer partners')
        ->toContain('sump pump contractors association NJ members')
        ->toContain('Zoeller find a dealer NJ')
        ->toContain('Zoeller authorized installer locator NJ')
        ->toContain('Montclair NJ local news')
        ->toContain('Essex County NJ newspaper')
        ->toContain('Clifton NJ community news submit a story');
    // The state-level searches run once for NJ, not once per office.
    expect(array_count_values(array_map('strtolower', $queries)))->each->toBe(1);
    expect(array_filter($plan, fn ($q) => $q['lane'] === LinkProspectKind::DealerLocator))->not->toBeEmpty();

    // Lanes the tenant switched off leave the plan.
    $site->forceFill(['link_lanes' => ['membership']])->save();
    $lanes = array_unique(array_map(fn ($q) => $q['lane']->value, app(LocalOpportunityQueries::class)->for($site->fresh())));
    expect(array_values($lanes))->toBe(['membership']);
});

it('keeps the local opportunity pages, drops national / aggregator / platform / own domains, and classifies the lane from the page', function () {
    $site = opportunitySite();
    $serp = new MockSerpProvider;
    $serp->setResults('Montclair NJ sponsorship opportunities', new SerpResultSet('Montclair NJ sponsorship opportunities', [
        new SerpResult(1, 'https://www.montclairlittleleague.org/sponsors', 'www.montclairlittleleague.org'),
        new SerpResult(2, 'https://www.facebook.com/groups/montclair', 'www.facebook.com'),
        new SerpResult(3, 'https://www.yelp.com/search?find_desc=sponsorship', 'www.yelp.com'),
        new SerpResult(4, 'https://sumppumpgurus.com/montclair-nj/', 'sumppumpgurus.com'),
        new SerpResult(5, 'https://www.homedepot.com/c/community', 'www.homedepot.com'),
    ]));
    $serp->setResults('Clifton NJ chamber of commerce members', new SerpResultSet('Clifton NJ chamber of commerce members', [
        new SerpResult(1, 'https://cliftonchamber.org/join', 'cliftonchamber.org'),
        new SerpResult(2, 'https://www.montclairlittleleague.org/sponsors', 'www.montclairlittleleague.org'),   // the same page, from a second search
    ]));
    $serp->setResults('Zoeller find a dealer NJ', new SerpResultSet('Zoeller find a dealer NJ', [
        new SerpResult(1, 'https://www.zoeller.com/find-a-dealer?state=NJ', 'www.zoeller.com'),
    ]));
    app()->instance(SerpProvider::class, $serp);
    $asked = [];
    app()->instance(BacklinkProvider::class, opportunityBacklinks(['cliftonchamber.org' => 31, 'zoeller.com' => 62], $asked));

    $result = app(LocalOpportunityFinder::class)->run($site);

    expect($result['status'])->toBe(LinkProspectRun::COMPLETE)
        ->and($result['kept'])->toBe(3)
        ->and($result['new'])->toBe(3)
        ->and($result['dropped_platform'])->toBe(1)      // facebook
        ->and($result['dropped_national'])->toBe(2)      // yelp (aggregator) + homedepot (national)
        ->and($result['by_lane'])->toBe(['sponsorship' => 1, 'membership' => 1, 'dealer_locator' => 1]);

    $rows = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('domain');
    expect($rows->keys()->sort()->values()->all())->toBe(['cliftonchamber.org', 'montclairlittleleague.org', 'zoeller.com']);

    $league = $rows['montclairlittleleague.org'];
    expect($league->kind)->toBe(LinkProspectKind::Sponsorship)
        ->and($league->url)->toBe('https://www.montclairlittleleague.org/sponsors')
        ->and($league->status)->toBe(LinkProspectStatus::New)
        ->and($league->offices)->toBe(['Montclair, NJ', 'Clifton, NJ'])   // seen from both offices' searches
        ->and($league->locality)->toBe(2)                                 // the town is in the URL; the state is not
        ->and($league->competitors)->toBe([]);
    expect($rows['cliftonchamber.org']->kind)->toBe(LinkProspectKind::Membership)
        ->and($rows['cliftonchamber.org']->rank)->toBe(31);
    // The dealer locator came from a state-level search: labelled with the state, no office, lane classified from the URL.
    expect($rows['zoeller.com']->kind)->toBe(LinkProspectKind::DealerLocator)
        ->and($rows['zoeller.com']->offices)->toBe(['NJ'])
        ->and($rows['zoeller.com']->location_id)->toBeNull();
    expect($asked)->toContain('cliftonchamber.org');

    $run = LinkProspectRun::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();
    expect($run->scope)->toBe('opportunities')
        ->and($run->queries)->toBe(count(app(LocalOpportunityQueries::class)->for($site)))
        ->and($run->returned)->toBe(8)
        ->and($run->kept)->toBe(3)
        ->and($run->dropped_spam)->toBe(1)
        ->and($run->dropped_rank)->toBe(2);
});

it('press is local or nothing: a town paper is kept as a PR opportunity, national and network outlets are dropped by name, a paper seen from offices in two states is dropped by the data', function () {
    $site = opportunitySite();
    // A third office in another state, so a metro paper can show up across a state line.
    $pa = [['long_name' => 'Trooper', 'short_name' => 'Trooper', 'types' => ['locality', 'political']], ['long_name' => 'Pennsylvania', 'short_name' => 'PA', 'types' => ['administrative_area_level_1', 'political']]];
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office', 'address_components' => $pa, 'home_county_geoid' => '42091', 'county_geoids' => []]);
    $serp = new MockSerpProvider;
    $serp->setResults('Montclair NJ local news', new SerpResultSet('Montclair NJ local news', [
        new SerpResult(1, 'https://montclairlocal.news/', 'montclairlocal.news'),
        new SerpResult(2, 'https://patch.com/new-jersey/montclair', 'patch.com'),
        new SerpResult(3, 'https://www.nj.com/essex/', 'www.nj.com'),
        new SerpResult(4, 'https://www.metrodailynews.com/nj', 'www.metrodailynews.com'),
    ]));
    $serp->setResults('Trooper PA local news', new SerpResultSet('Trooper PA local news', [
        new SerpResult(1, 'https://www.metrodailynews.com/pa', 'www.metrodailynews.com'),
        new SerpResult(2, 'https://trooperweekly.com/', 'trooperweekly.com'),
    ]));
    app()->instance(SerpProvider::class, $serp);
    $asked = [];
    app()->instance(BacklinkProvider::class, opportunityBacklinks([], $asked));

    $result = app(LocalOpportunityFinder::class)->run($site);

    $rows = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('domain');
    expect($rows->keys()->sort()->values()->all())->toBe(['montclairlocal.news', 'trooperweekly.com'])
        ->and($rows['montclairlocal.news']->kind)->toBe(LinkProspectKind::Press)
        ->and($rows['montclairlocal.news']->offices)->toBe(['Montclair, NJ'])
        ->and($result['by_lane'])->toBe(['press' => 2])
        ->and($result['dropped_national'])->toBe(3);   // patch.com + nj.com by name, metrodailynews.com by two states
    expect(LocalOpportunityFinder::isNationalPress('patch.com'))->toBeTrue()
        ->and(LocalOpportunityFinder::isNationalPress('www.nj.com'))->toBeTrue()    // a subdomain of a national outlet is still national
        ->and(LocalOpportunityFinder::isNationalPress('nj.com'))->toBeTrue()
        ->and(LocalOpportunityFinder::isNationalPress('montclairlocal.news'))->toBeFalse();
});

it('a re-run refreshes the page and offices but keeps the operator\'s status and notes, and never relabels a competitor-intersect row', function () {
    $site = opportunitySite();
    $serp = new MockSerpProvider;
    $serp->setResults('Montclair NJ sponsorship opportunities', new SerpResultSet('Montclair NJ sponsorship opportunities', [
        new SerpResult(1, 'https://montclairrotary.org/sponsor-a-table', 'montclairrotary.org'),
        new SerpResult(2, 'https://essexcountynews.com/sponsors', 'essexcountynews.com'),
    ]));
    app()->instance(SerpProvider::class, $serp);
    $asked = [];
    app()->instance(BacklinkProvider::class, opportunityBacklinks([], $asked));
    LinkProspect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'domain' => 'montclairrotary.org', 'kind' => LinkProspectKind::Local->value, 'status' => LinkProspectStatus::Contacted->value,
        'notes' => 'spoke to Dana', 'competitors' => ['rivalplumbing.com'], 'competitor_count' => 1, 'first_seen' => '2026-09-01', 'found_at' => now(),
    ]);
    LinkProspect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'domain' => 'essexcountynews.com', 'kind' => LinkProspectKind::Editorial->value, 'status' => LinkProspectStatus::New->value,
        'competitors' => ['dryguys.com'], 'competitor_count' => 1, 'first_seen' => '2026-09-01', 'found_at' => now(),
    ]);

    $result = app(LocalOpportunityFinder::class)->run($site);
    expect($result['new'])->toBe(0)->and($result['refreshed'])->toBe(2);

    $rotary = LinkProspect::withoutGlobalScope(SiteScope::class)->where('domain', 'montclairrotary.org')->sole();
    expect($rotary->kind)->toBe(LinkProspectKind::Sponsorship)            // a plain "local site" takes the more useful lane label
        ->and($rotary->status)->toBe(LinkProspectStatus::Contacted)
        ->and($rotary->notes)->toBe('spoke to Dana')
        ->and($rotary->competitors)->toBe(['rivalplumbing.com'])
        ->and($rotary->url)->toBe('https://montclairrotary.org/sponsor-a-table');
    $news = LinkProspect::withoutGlobalScope(SiteScope::class)->where('domain', 'essexcountynews.com')->sole();
    expect($news->kind)->toBe(LinkProspectKind::Editorial)                 // an editorial row keeps its intersect classification
        ->and($news->url)->toBe('https://essexcountynews.com/sponsors');
});

it('the command previews the plan without searching, and reports the run by lane', function () {
    $site = opportunitySite();
    $serp = new MockSerpProvider;
    $serp->setResults('Clifton NJ chamber of commerce members', new SerpResultSet('Clifton NJ chamber of commerce members', [
        new SerpResult(1, 'https://cliftonchamber.org/join', 'cliftonchamber.org'),
    ]));
    app()->instance(SerpProvider::class, $serp);
    $asked = [];
    app()->instance(BacklinkProvider::class, opportunityBacklinks([], $asked));

    $this->artisan(FindLocalOpportunitiesCommand::class, ['--site' => $site->id, '--preview' => true])
        ->expectsOutputToContain('Zoeller find a dealer NJ')
        ->assertSuccessful();
    expect(LinkProspect::withoutGlobalScope(SiteScope::class)->count())->toBe(0);

    $this->artisan(FindLocalOpportunitiesCommand::class, ['--site' => $site->id])
        ->expectsOutputToContain('1 opportunity domain(s) kept (1 new, 0 refreshed); 0 national / aggregator and 0 platform dropped. By lane: 1 membership.')
        ->assertSuccessful();
    expect(LinkProspect::withoutGlobalScope(SiteScope::class)->count())->toBe(1);
});

it('the Business step saves the link-building fields with the guardrails: cap clamped to 20, sender only on the tenant\'s own domain', function () {
    Filament::setCurrentPanel('admin');
    config()->set('launchpad.new_setup_enabled', true);
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = opportunitySite();
    app(ActiveTenant::class)->set((string) $site->id);

    Livewire::test(BusinessStep::class)
        ->set('brands', "Zoeller\nLiberty Pumps, Wayne\n\n")
        ->set('linkLanes', ['sponsorship', 'membership'])
        ->set('sponsorshipBudgetMin', '500')
        ->set('sponsorshipBudgetMax', '3000')
        ->set('outreachMonthlyCap', '45')
        ->set('outreachFromEmail', 'eric@gmail.com')
        ->set('outreachFromName', 'Eric at Sump Pump Gurus')
        ->call('save');
    $site->refresh();
    expect($site->brands)->toBe(['Zoeller', 'Liberty Pumps', 'Wayne'])
        ->and($site->link_lanes)->toBe(['sponsorship', 'membership'])
        ->and($site->sponsorship_budget_min)->toBe(500)
        ->and($site->sponsorship_budget_max)->toBe(3000)
        ->and($site->outreach_monthly_cap)->toBe(20)        // never above the platform ceiling
        ->and($site->outreach_from_email)->toBeNull()       // gmail.com is not the client's domain
        ->and($site->outreach_from_name)->toBe('Eric at Sump Pump Gurus');

    Livewire::test(BusinessStep::class)
        ->set('linkLanes', ['sponsorship', 'membership', 'dealer_locator', 'job_board', 'press'])
        ->set('outreachMonthlyCap', '12')
        ->set('outreachFromEmail', 'Outreach@SumpPumpGurus.com')
        ->call('save');
    $site->refresh();
    expect($site->link_lanes)->toBeNull()                   // every lane = no restriction stored
        ->and($site->outreach_monthly_cap)->toBe(12)
        ->and($site->outreach_from_email)->toBe('outreach@sumppumpgurus.com');
});

it('the Link prospects table groups rows by office, with state-wide bodies under the corporate location only and competitor links last', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = opportunitySite();
    $site->forceFill(['corporate_city' => 'Clifton', 'corporate_state' => 'NJ'])->save();
    app(ActiveTenant::class)->set((string) $site->id);
    [$montclair, $clifton] = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('created_at')->get()->all();
    $mk = fn (string $domain, array $o) => LinkProspect::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'domain' => $domain, 'kind' => LinkProspectKind::Sponsorship->value, 'status' => LinkProspectStatus::New->value,
        'competitors' => [], 'competitor_count' => 0, 'first_seen' => '2026-10-01', 'found_at' => now(),
    ], $o));
    $mk('njbuilders.org', ['kind' => LinkProspectKind::Membership->value, 'offices' => ['NJ'], 'location_id' => null]);
    $mk('cliftonrotary.org', ['location_id' => $clifton->id, 'offices' => ['Clifton, NJ']]);
    $mk('montclairlittleleague.org', ['location_id' => $montclair->id, 'offices' => ['Montclair, NJ']]);
    $mk('njhomeblog.com', ['kind' => LinkProspectKind::Local->value, 'competitors' => ['rival.com'], 'competitor_count' => 1]);

    // The landing is one card per service area, in office order, then Corporate, then Competitor links — each with its strongest rows.
    $page = Livewire::test(LinkProspectsPage::class)
        ->assertSet('office', '')
        ->assertSeeInOrder(['Montclair office', 'montclairlittleleague.org', 'Clifton office', 'cliftonrotary.org', 'Corporate · state-wide', 'njbuilders.org', 'Competitor links', 'njhomeblog.com']);
    $groups = $page->instance()->groups;
    expect(array_column($groups, 'key'))->toBe([$montclair->id, $clifton->id, 'corporate', 'competitors'])
        ->and(array_map(fn ($g) => count($g['rows']), $groups))->toBe([1, 1, 1, 1]);
    $cards = $page->instance()->officeCards;
    expect(array_column($cards, 'count'))->toBe([1, 1, 1, 1])
        ->and($cards[0]['by_kind'])->toBe(['Sponsorship' => 1])
        ->and($cards[2]['by_kind'])->toBe(['Membership' => 1])
        ->and($cards[2]['label'])->toContain('Corporate (Clifton, NJ)');

    // Click-through: one service area, only its rows, as cards with the row controls; back returns to the cards.
    $page->call('openOffice', (string) $clifton->id)
        ->assertSet('office', (string) $clifton->id)
        ->assertSee('All service areas')
        ->assertSee('Clifton office')
        ->assertSee('cliftonrotary.org')
        ->assertDontSee('montclairlittleleague.org')
        ->assertDontSee('njbuilders.org')
        ->assertSee('Pitch')
        ->call('openOffice', 'corporate')
        ->assertSee('Corporate · state-wide')->assertSee('njbuilders.org')->assertDontSee('cliftonrotary.org')
        ->call('closeOffice')
        ->assertSet('office', '')
        ->assertSee('Montclair office');
});

it('the Link prospects page queues the opportunity search and filters by lane', function () {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = opportunitySite();
    app(ActiveTenant::class)->set((string) $site->id);
    LinkProspect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'domain' => 'cliftonchamber.org', 'kind' => LinkProspectKind::Membership->value, 'status' => LinkProspectStatus::New->value,
        'url' => 'https://cliftonchamber.org/join', 'offices' => ['Clifton, NJ'], 'locality' => 2, 'competitors' => [], 'competitor_count' => 0, 'first_seen' => '2026-10-01', 'found_at' => now(),
    ]);
    LinkProspect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'domain' => 'njhomeblog.com', 'kind' => LinkProspectKind::Local->value, 'status' => LinkProspectStatus::New->value,
        'competitors' => ['rivalplumbing.com'], 'competitor_count' => 1, 'first_seen' => '2026-10-01', 'found_at' => now(),
    ]);

    Livewire::test(LinkProspectsPage::class)
        ->assertSee('Find local opportunities')
        ->assertSee('cliftonchamber.org')->assertSee('njhomeblog.com')->assertSee('Clifton, NJ')
        ->set('kind', 'membership')
        ->assertSee('cliftonchamber.org')->assertDontSee('njhomeblog.com')
        ->set('kind', 'competitors')
        ->assertDontSee('cliftonchamber.org')->assertSee('njhomeblog.com')
        ->call('findLocalOpportunities');
    Queue::assertPushed(FindLocalOpportunities::class);
});
