<?php

use App\Models\Site;

it('takes a partial site name like every other command, and lists the options when it cannot match', function () {
    Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.example']);

    // "sump" used to be a dead end here: the lookup demanded an exact id or brand name. Resolving it now
    // means the run proceeds to the plan (this tenant has no town pages yet, so it reports nothing more).
    $this->artisan('launchpad:anchor-town-pages', ['--site' => 'sump'])
        ->expectsOutputToContain('town-page geo-anchor plan')
        ->assertExitCode(0);

    // A real miss names the alternatives rather than leaving you to guess the exact string.
    $this->artisan('launchpad:anchor-town-pages', ['--site' => 'nothing-like-this'])
        ->expectsOutputToContain('No site matches [nothing-like-this]. Available sites:')
        ->expectsOutputToContain('Sump Pump Gurus')
        ->assertExitCode(1);
});

it('refuses an ambiguous name instead of picking a tenant', function () {
    Site::factory()->create(['brand_name' => 'Gurus North', 'domain_url' => 'https://north.example']);
    Site::factory()->create(['brand_name' => 'Gurus South', 'domain_url' => 'https://south.example']);

    $this->artisan('launchpad:anchor-town-pages', ['--site' => 'gurus'])
        ->expectsOutputToContain('is ambiguous — it matches 2 sites')
        ->assertExitCode(1);
});
