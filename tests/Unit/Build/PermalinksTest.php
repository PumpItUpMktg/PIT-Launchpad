<?php

use App\Build\Permalinks;
use App\Support\PublicUrl;

it('normalizes a slug to a trailing-slash link segment', function () {
    expect(Permalinks::slugPath('about'))->toBe('about/')
        ->and(Permalinks::slugPath('/about'))->toBe('about/')          // stray leading slash tolerated
        ->and(Permalinks::slugPath('/about/'))->toBe('about/')          // already-slashed is idempotent
        ->and(Permalinks::slugPath('montclair-nj/millburn-nj'))->toBe('montclair-nj/millburn-nj/')
        ->and(Permalinks::slugPath(''))->toBe('')                       // home/root → no segment
        ->and(Permalinks::slugPath('/'))->toBe('');
});

it('agrees with PublicUrl on the path portion, so a rendered link matches the canonical exactly', function () {
    // A rendered internal link is "{home}".slugPath($slug); it must equal the canonical PublicUrl builds.
    expect('https://x.test/'.Permalinks::slugPath('about'))
        ->toBe(PublicUrl::for('https://x.test', 'about'))              // both → https://x.test/about/
        ->and('https://x.test/'.Permalinks::slugPath(''))
        ->toBe(PublicUrl::for('https://x.test', ''));                  // both → https://x.test/
});
