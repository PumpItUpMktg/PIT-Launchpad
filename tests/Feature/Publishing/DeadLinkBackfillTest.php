<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Links\DeadLinkBackfill;
use Illuminate\Support\Facades\Artisan;

function bfPage(Site $s, string $slug, string $body = ''): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'slug' => $slug, 'title' => $slug, 'body' => $body, 'slot_payload' => [],
    ]);
}

function bfResolvable(array $plan, string $from): ?array
{
    return collect($plan['resolvable'])->firstWhere('from', $from);
}

it('resolves a numbered path to its clean sibling', function () {
    $site = Site::factory()->create();
    bfPage($site, 'spring-city-pa/allentown-pa');                                  // the live clean page
    bfPage($site, 'edison', '<a href="/spring-city-pa/allentown-pa-3">x</a>');     // a live page linking the dead -3

    $plan = app(DeadLinkBackfill::class)->plan($site);
    $r = bfResolvable($plan, '/spring-city-pa/allentown-pa-3');

    expect($r)->not->toBeNull()
        ->and($r['to'])->toBe('/spring-city-pa/allentown-pa')
        ->and($r['rule'])->toBe('numbered-sibling');
});

it('resolves a flat path to its unique live nested page (last-segment match)', function () {
    $site = Site::factory()->create();
    bfPage($site, 'trooper-pa/abington-pa');                          // the live nested page
    bfPage($site, 'bristol', '<a href="/abington-pa">x</a>');        // links the flat form (dead)

    $r = bfResolvable(app(DeadLinkBackfill::class)->plan($site), '/abington-pa');

    expect($r)->not->toBeNull()
        ->and($r['to'])->toBe('/trooper-pa/abington-pa')
        ->and($r['rule'])->toBe('unique-last-segment');
});

it('resolves the /home marker to /', function () {
    $site = Site::factory()->create();
    bfPage($site, 'about', '<a href="/home">home</a>');

    $r = bfResolvable(app(DeadLinkBackfill::class)->plan($site), '/home');

    expect($r)->not->toBeNull()->and($r['to'])->toBe('/')->and($r['rule'])->toBe('home');
});

it('reports an unresolvable dead path — never invents a redirect to a wrong page', function () {
    $site = Site::factory()->create();
    // A link to a held/removed-duplicate town with NO live twin anywhere.
    bfPage($site, 'piscataway', '<a href="/fallston-md/3-bel-air-md">x</a>');

    $plan = app(DeadLinkBackfill::class)->plan($site);

    expect(bfResolvable($plan, '/fallston-md/3-bel-air-md'))->toBeNull() // not resolvable
        ->and(collect($plan['unresolvable'])->pluck('from'))->toContain('/fallston-md/3-bel-air-md');
});

it('does not resolve when the last segment is ambiguous (two live pages share it)', function () {
    $site = Site::factory()->create();
    bfPage($site, 'trooper-pa/springfield');
    bfPage($site, 'montclair-nj/springfield');
    bfPage($site, 'linker', '<a href="/springfield">x</a>'); // flat, but two live nested "springfield"

    $plan = app(DeadLinkBackfill::class)->plan($site);

    expect(bfResolvable($plan, '/springfield'))->toBeNull() // ambiguous → not guessed
        ->and(collect($plan['unresolvable'])->pluck('from'))->toContain('/springfield');
});

it('apply writes the 301 and clears the resolvable set on re-read', function () {
    $site = Site::factory()->create();
    bfPage($site, 'spring-city-pa/allentown-pa');
    bfPage($site, 'edison', '<a href="/spring-city-pa/allentown-pa-3">x</a>');

    expect(app(DeadLinkBackfill::class)->apply($site))->toBe(1)
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/spring-city-pa/allentown-pa-3')->first()->to_url)
        ->toBe('/spring-city-pa/allentown-pa');

    // Re-read: the path now has a redirect, so the audit no longer counts it dead → nothing left to resolve.
    expect(app(DeadLinkBackfill::class)->plan($site)['resolvable'])->toBe([]);
});

it('command is report-only by default and writes nothing', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    bfPage($site, 'spring-city-pa/allentown-pa');
    bfPage($site, 'edison', '<a href="/spring-city-pa/allentown-pa-3">x</a> <a href="/fallston-md/3-bel-air-md">y</a>');

    $code = Artisan::call('launchpad:backfill-dead-link-redirects', ['--site' => $site->id]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('/spring-city-pa/allentown-pa-3  →  /spring-city-pa/allentown-pa')
        ->and($out)->toContain('Unresolvable')
        ->and($out)->toContain('would get a 301')
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
