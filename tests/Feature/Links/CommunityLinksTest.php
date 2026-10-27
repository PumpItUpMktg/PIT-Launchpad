<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\LinkProspectsPage;
use App\Jobs\PublishContent;
use App\Links\CommunityLinks;
use App\Models\Content;
use App\Models\LinkProspect;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function wonProspect(Site $site, string $domain, LinkProspectKind $kind, ?string $locationId, array $o = []): LinkProspect
{
    return LinkProspect::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'domain' => $domain, 'kind' => $kind->value, 'status' => LinkProspectStatus::Acquired->value, 'location_id' => $locationId,
        'url' => "https://{$domain}/sponsors", 'offices' => [], 'locality' => 1, 'competitors' => [], 'competitor_count' => 0, 'first_seen' => '2026-10-01', 'found_at' => now(),
    ], $o));
}

it('an office hub names the won sponsorships, memberships and listings — its own office\'s and the state-wide ones — never press, competitor links or anything not yet won', function () {
    $site = Site::factory()->create();
    $montclair = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office']);
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office']);
    wonProspect($site, 'montclairlittleleague.org', LinkProspectKind::Sponsorship, $montclair->id, ['profile' => ['name' => 'Montclair Little League']]);
    wonProspect($site, 'njbuilders.org', LinkProspectKind::Membership, null, ['profile' => ['name' => 'New Jersey Builders Association']]);          // state-wide → every hub
    wonProspect($site, 'zoeller.com', LinkProspectKind::DealerLocator, null, ['url' => 'https://www.zoeller.com/find-a-dealer?state=NJ']);     // links the locator page
    wonProspect($site, 'trooperrotary.org', LinkProspectKind::Sponsorship, $trooper->id);                                                      // another office's
    wonProspect($site, 'montclairlocal.news', LinkProspectKind::Press, $montclair->id);                                                        // coverage is not a relationship
    wonProspect($site, 'njhomeblog.com', LinkProspectKind::Local, $montclair->id);                                                             // a competitor-link row
    wonProspect($site, 'cliftonchamber.org', LinkProspectKind::Membership, $montclair->id, ['status' => LinkProspectStatus::Contacted->value]);  // not won yet

    $items = app(CommunityLinks::class)->forLocation($site, $montclair);
    expect(array_map(fn ($i) => [$i['role'], $i['label'], $i['url']], $items))->toBe([
        ['Proud sponsor of', 'Montclair Little League', 'https://montclairlittleleague.org/'],
        ['Member of', 'New Jersey Builders Association', 'https://njbuilders.org/'],
        ['Listed installer with', 'zoeller.com', 'https://www.zoeller.com/find-a-dealer?state=NJ'],
    ]);
    expect(array_column(app(CommunityLinks::class)->forLocation($site, $trooper), 'label'))->toBe(['trooperrotary.org', 'New Jersey Builders Association', 'zoeller.com']);
});

it('marking a prospect Won on the Link prospects page re-pushes its office hub (every hub for a state-wide one); un-winning re-pushes too; a press row never does', function () {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();
    $montclair = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office']);
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office']);
    $page = fn (Location $l) => Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'location_id' => $l->id, 'status' => ContentStatus::Published, 'title' => $l->name, 'slug' => strtolower(explode(' ', $l->name)[0]).'-nj']);
    $montclairHub = $page($montclair);
    $trooperHub = $page($trooper);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'location_id' => null, 'parent_location_id' => $montclair->id, 'status' => ContentStatus::Published, 'title' => 'Verona, NJ', 'slug' => 'montclair-nj/verona-nj']);
    app(ActiveTenant::class)->set((string) $site->id);
    $league = wonProspect($site, 'montclairlittleleague.org', LinkProspectKind::Sponsorship, $montclair->id, ['status' => LinkProspectStatus::Contacted->value]);
    $assoc = wonProspect($site, 'njbuilders.org', LinkProspectKind::Membership, null, ['status' => LinkProspectStatus::Replied->value]);
    $press = wonProspect($site, 'montclairlocal.news', LinkProspectKind::Press, $montclair->id, ['status' => LinkProspectStatus::Contacted->value]);

    $lw = Livewire::test(LinkProspectsPage::class);
    $lw->call('setStatus', $league->id, 'acquired');
    Queue::assertPushed(PublishContent::class, 1);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $j) => $j->contentId === $montclairHub->id);   // the office hub, not the town page

    $lw->call('setStatus', $assoc->id, 'acquired');
    // Both hubs for the state-wide membership (the publish job is unique per page, so Montclair's pending push absorbs the repeat).
    Queue::assertPushed(PublishContent::class, fn (PublishContent $j) => $j->contentId === $trooperHub->id);
    Queue::assertPushed(PublishContent::class, 2);
    expect(app(CommunityLinks::class)->hubPagesFor($assoc->fresh()))->toEqualCanonicalizing([$montclairHub->id, $trooperHub->id]);

    // Press: nothing to show on a hub, so nothing is re-pushed; the town page is never a target.
    $lw->call('setStatus', $press->id, 'acquired');
    expect(app(CommunityLinks::class)->repushFor($press->fresh()))->toBe(0);
    Queue::assertPushed(PublishContent::class, 2);

    // Un-winning re-pushes the hub (the hub drops them); an unchanged status does nothing.
    expect(app(CommunityLinks::class)->repushFor($league->fresh()))->toBe(1);
    $lw->call('setStatus', $league->id, 'contacted');
    expect($league->fresh()->status)->toBe(LinkProspectStatus::Contacted);
});
