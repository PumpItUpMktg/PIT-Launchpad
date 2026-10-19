<?php

use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Enums\UserRole;
use App\Filament\Pages\LinkProspectsPage;
use App\Jobs\FindLinkProspects;
use App\Models\LinkProspect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

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
