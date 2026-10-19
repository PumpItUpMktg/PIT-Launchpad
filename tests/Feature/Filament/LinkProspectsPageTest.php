<?php

use App\ContentEngine\Drafting\VoiceResolver;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Enums\UserRole;
use App\Filament\Pages\LinkProspectsPage;
use App\Jobs\FindLinkProspects;
use App\Links\OutreachKitDrafter;
use App\Models\LinkProspect;
use App\Models\LinkProspectRun;
use App\Models\Location;
use App\Models\OutreachKit;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeClaudeClient;

beforeEach(function (): void {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://sumppumpgurus.com']);
    session([ActiveTenant::SESSION_KEY => $this->site->id]);
});

it('is operator-only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(LinkProspectsPage::canAccess())->toBeFalse();
});

it('lists the open prospects strongest first, filters by status, and lets the operator set a status and a note', function () {
    $a = LinkProspect::withoutGlobalScope(SiteScope::class)->create(['site_id' => $this->site->id, 'domain' => 'bucks-chamber.org', 'rank' => 41, 'competitors' => ['rivalplumbing.com', 'dryguys.com'], 'competitor_count' => 2, 'kind' => LinkProspectKind::Local, 'status' => LinkProspectStatus::New, 'found_at' => now()]);
    LinkProspect::withoutGlobalScope(SiteScope::class)->create(['site_id' => $this->site->id, 'domain' => 'hotfrog.com', 'rank' => 78, 'competitors' => ['dryguys.com'], 'competitor_count' => 1, 'kind' => LinkProspectKind::Directory, 'status' => LinkProspectStatus::Dismissed, 'found_at' => now()]);

    Livewire::test(LinkProspectsPage::class)
        ->assertOk()
        ->assertSee('bucks-chamber.org')
        ->assertDontSee('hotfrog.com')          // dismissed is not "open"
        ->set('status', 'dismissed')
        ->assertSee('hotfrog.com')
        ->assertDontSee('bucks-chamber.org')
        ->call('setStatus', $a->id, 'contacted')
        ->call('setNotes', $a->id, '  spoke to the membership desk  ');

    expect($a->fresh()->status)->toBe(LinkProspectStatus::Contacted)
        ->and($a->fresh()->notes)->toBe('spoke to the membership desk');
});

it('find prospects queues one intersect for the working tenant', function () {
    Livewire::test(LinkProspectsPage::class)->call('findProspects');

    Queue::assertPushed(FindLinkProspects::class, fn (FindLinkProspects $job): bool => $job->siteId === (string) $this->site->id);
});

it('opens a prospect\'s pitch panel and drafts the kit for the chosen office', function () {
    app()->instance(OutreachKitDrafter::class, new OutreachKitDrafter(claude: new FakeClaudeClient('{"short_description":"s","long_description":"l","categories":[],"pitch_subject":"Subject here","pitch_body":"Body here"}'), voices: app(VoiceResolver::class)));
    $office = Location::factory()->create(['site_id' => $this->site->id, 'name' => 'Hoboken']);
    $p = LinkProspect::withoutGlobalScope(SiteScope::class)->create(['site_id' => $this->site->id, 'domain' => 'hudson-chamber.org', 'rank' => 40, 'competitors' => ['rivalplumbing.com'], 'competitor_count' => 1, 'kind' => LinkProspectKind::Local, 'status' => LinkProspectStatus::New, 'found_at' => now()]);

    Livewire::test(LinkProspectsPage::class)
        ->call('openKit', $p->id)
        ->assertSee('Draft pitch')
        ->set('kitLocationId', $office->id)
        ->call('draftKit', false)
        ->assertSee('Subject here')
        ->assertSee('Body here');

    expect(OutreachKit::withoutGlobalScope(SiteScope::class)->where('target_domain', 'hudson-chamber.org')->value('location_id'))->toBe((string) $office->id);
});

it('shows the last run: who was asked about, who was excluded, what was kept', function () {
    LinkProspectRun::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $this->site->id, 'status' => 'complete',
        'targets' => [['domain' => 'oriolebw.com', 'appearances' => 12, 'source' => 'map_pack'], ['domain' => 'patriotpumpservice.com', 'appearances' => 240, 'source' => 'organic']],
        'excluded' => [['domain' => 'barbaraemergencyrestorationpompanobeachfl.com', 'appearances' => 1307, 'share' => 0.91]],
        'returned' => 818, 'kept' => 4, 'new' => 0, 'refreshed' => 4, 'pruned' => 1, 'dropped_spam' => 803, 'dropped_rank' => 11, 'ran_at' => now(),
    ]);

    Livewire::test(LinkProspectsPage::class)
        ->assertOk()
        ->assertSee('oriolebw.com')
        ->assertSee('map pack, 12 cells')
        ->assertSee('above you in 240 town results')
        ->assertSee('barbaraemergencyrestorationpompanobeachfl.com')
        ->assertSee('91% of one scan')
        ->assertSee('803 dropped as spam');
});
