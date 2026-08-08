<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\CurrentSite;

it('applies coverage 301s for a site from a URL-list file', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.example']);
    CurrentSite::set($site->id);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-repair', 'title' => 'Sump Pump Repair']);

    $file = tempnam(sys_get_temp_dir(), 'urls').'.txt';
    file_put_contents($file, implode("\n", [
        '# legacy 404 export',
        'https://spg.example/sump-pump-repair-cost/',
        'https://spg.example/check-valve-care/',
        '',
    ]));

    $this->artisan('launchpad:cover-legacy-404s', ['--site' => 'Sump Pump Gurus', '--from' => $file, '--apply' => true])
        ->assertSuccessful();

    @unlink($file);

    $rows = Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($r): bool => (int) $r->code === 301))->toBeTrue();
    expect($rows->firstWhere('from_url', '/sump-pump-repair-cost')->to_url)->toBe('/sump-pump-repair');
});

it('errors when the site does not resolve', function () {
    $this->artisan('launchpad:cover-legacy-404s', ['--site' => 'nope-no-such'])
        ->assertFailed();
});
