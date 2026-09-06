<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;

afterEach(fn () => Carbon::setTestNow());

it('renders the stamp with a semantic state class, the wording, and the exact timestamp on hover', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');

    $html = Blade::render(
        '<x-lp.freshness-stamp :last-checked="$lc" :interval="$i" noun="positions" />',
        ['lc' => Carbon::parse('2026-09-05 06:00:00'), 'i' => 86400],
    );

    expect($html)->toContain('Positions as of 5 Sep')          // wording (reused "as of" treatment)
        ->toContain('lp-fresh--late')                          // semantic severity class (30h old, daily)
        ->toContain('data-fresh-state="late"')                 // semantic state in the markup
        ->toContain('title="')                                 // exact timestamp on hover
        ->not->toContain('#');                                 // no hardcoded colour in the markup
});

it('renders an honest never-checked stamp with no title', function () {
    $html = Blade::render('<x-lp.freshness-stamp :interval="86400" noun="positions" />');

    expect($html)->toContain('Positions — never checked')
        ->toContain('data-fresh-state="never_checked"')
        ->not->toContain('title=');
});
