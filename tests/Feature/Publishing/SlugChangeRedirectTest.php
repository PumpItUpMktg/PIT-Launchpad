<?php

use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\SlugChangeRedirect;

function redirectFrom(Site $site, string $from): ?Redirect
{
    return Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', $from)->first();
}

it('records a 301 from the old path to the new nested path', function () {
    $site = Site::factory()->create();

    app(SlugChangeRedirect::class)->record($site->id, 'sump-pump-repair', 'sump-pump-maintenance/sump-pump-repair');

    $r = redirectFrom($site, '/sump-pump-repair');
    expect($r)->not->toBeNull()
        ->and($r->to_url)->toBe('/sump-pump-maintenance/sump-pump-repair')
        ->and((int) $r->code)->toBe(301)
        ->and($r->status)->toBe('active')
        ->and($r->source->value)->toBe('slug_change');
});

it('is idempotent by from_url and repoints to the latest target', function () {
    $site = Site::factory()->create();
    $svc = app(SlugChangeRedirect::class);

    $svc->record($site->id, 'a', 'b');
    $svc->record($site->id, 'a', 'c'); // same from, newer target

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/a')->count())->toBe(1)
        ->and(redirectFrom($site, '/a')->to_url)->toBe('/c');
});

it('flattens a chain — an existing redirect to the old path follows to the new one', function () {
    $site = Site::factory()->create();
    $svc = app(SlugChangeRedirect::class);

    $svc->record($site->id, 'a', 'b'); // /a -> /b
    $svc->record($site->id, 'b', 'c'); // /b -> /c, and /a must now -> /c (no A->B->C chain)

    expect(redirectFrom($site, '/a')->to_url)->toBe('/c')
        ->and(redirectFrom($site, '/b')->to_url)->toBe('/c');
});

it('is a no-op for an unchanged slug or the site root', function () {
    $site = Site::factory()->create();
    $svc = app(SlugChangeRedirect::class);

    $svc->record($site->id, 'same', 'same');
    $svc->record($site->id, '', 'x');   // from resolves to "/"
    $svc->record($site->id, 'x', '');   // to resolves to "/"

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
