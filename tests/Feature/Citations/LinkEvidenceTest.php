<?php

use App\Citations\LinkEvidence;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Directory;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBacklink;

function leLink(Site $site, string $domain, string $path, bool $broken = false): void
{
    SiteBacklink::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'domain_from' => $domain, 'url_from' => "https://{$domain}/biz/spg", 'url_to' => "https://sumppumpgurus.com{$path}",
        'target_path' => $path === '/' ? '/' : rtrim($path, '/'), 'domain_from_rank' => 80, 'dofollow' => true, 'is_broken' => $broken, 'synced_at' => now(),
    ]);
}

it('tells, per directory, whether the index link lands on this office, a sibling, the home page, or a page we do not serve', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $hoboken = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hoboken']);
    $montclair = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'slug' => 'hoboken-nj', 'location_id' => $hoboken->id]);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'slug' => 'montclair-nj', 'location_id' => $montclair->id]);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'slug' => 'hoboken-nj/weehawken-nj', 'parent_location_id' => $hoboken->id]);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-repair']);

    $yelp = Directory::factory()->create(['domain' => 'yelp.com']);
    $mapquest = Directory::factory()->create(['domain' => 'mapquest.com']);
    $bbb = Directory::factory()->create(['domain' => 'bbb.org']);
    $porch = Directory::factory()->create(['domain' => 'porch.com']);
    $houzz = Directory::factory()->create(['domain' => 'houzz.com']);
    $angi = Directory::factory()->create(['domain' => 'angi.com']);
    $nextdoor = Directory::factory()->create(['domain' => 'nextdoor.com']);

    leLink($site, 'www.yelp.com', '/hoboken-nj/');          // this office, via www + trailing slash
    leLink($site, 'mapquest.com', '/');                      // home page
    leLink($site, 'bbb.org', '/montclair-nj');               // the sibling office
    leLink($site, 'pro.porch.com', '/hoboken-nj/weehawken-nj'); // a town page under this office, via a subdomain
    leLink($site, 'houzz.com', '/sump-pump-repair');         // a page with no office
    leLink($site, 'angi.com', '/newcity', broken: true);     // a page we do not serve, index says broken
    leLink($site, 'randomblog.example', '/hoboken-nj');      // not a directory — ignored

    $e = (new LinkEvidence)->forLocation($hoboken);

    expect(array_keys($e))->toHaveCount(6)
        ->and($e[$yelp->id]['state'])->toBe(LinkEvidence::THIS)
        ->and($e[$yelp->id]['listing_url'])->toBe('https://www.yelp.com/biz/spg')
        ->and($e[$mapquest->id]['state'])->toBe(LinkEvidence::HOME)
        ->and($e[$bbb->id]['state'])->toBe(LinkEvidence::SIBLING)
        ->and($e[$bbb->id]['label'])->toBe('Links to Montclair')
        ->and($e[$porch->id]['state'])->toBe(LinkEvidence::THIS)
        ->and($e[$houzz->id]['state'])->toBe(LinkEvidence::OTHER)
        ->and($e[$angi->id]['state'])->toBe(LinkEvidence::UNKNOWN)
        ->and($e[$angi->id]['index_broken'])->toBeTrue()
        ->and($e)->not->toHaveKey($nextdoor->id);

    // The same rows read from Montclair: Yelp's strongest link is Hoboken's, BBB's is this office's.
    $m = (new LinkEvidence)->forLocation($montclair);
    expect($m[$yelp->id]['state'])->toBe(LinkEvidence::SIBLING)->and($m[$bbb->id]['state'])->toBe(LinkEvidence::THIS);
});

it('returns nothing when the site has never been synced', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id]);
    Directory::factory()->create(['domain' => 'yelp.com']);

    expect((new LinkEvidence)->forLocation($loc))->toBe([]);
});
