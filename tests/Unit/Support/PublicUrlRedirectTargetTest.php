<?php

use App\Support\PublicUrl;

it('sends a page path to its trailing-slash permalink so the 301 lands in one hop', function (string $stored, string $sent) {
    expect(PublicUrl::redirectTarget($stored))->toBe($sent);
})->with([
    'nested town page' => ['/downingtown-pa/atglen-pa', '/downingtown-pa/atglen-pa/'],
    'office page' => ['/hoboken-nj', '/hoboken-nj/'],
    'already slashed' => ['/hoboken-nj/', '/hoboken-nj/'],
    'absolute url to a page' => ['https://sumppumpgurus.com/trooper-pa/abington-pa', 'https://sumppumpgurus.com/trooper-pa/abington-pa/'],
    'absolute url, bare host' => ['https://sumppumpgurus.com', 'https://sumppumpgurus.com/'],
    'padded' => ['  /trooper-pa ', '/trooper-pa/'],
]);

it('leaves anything that is not a page path exactly as stored', function (string $stored) {
    expect(PublicUrl::redirectTarget($stored))->toBe($stored);
})->with([
    'a 410 row has no destination' => [''],
    'root' => ['/'],
    'a file' => ['/sitemap.xml'],
    'a pdf' => ['/downloads/guide.pdf'],
    'a query string' => ['/search?q=sump'],
    'a fragment' => ['/faq#warranty'],
    'an external file' => ['https://example.com/brochure.pdf'],
]);
