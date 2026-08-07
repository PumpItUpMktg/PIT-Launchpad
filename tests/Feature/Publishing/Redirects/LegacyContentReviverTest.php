<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\LegacyContentReviver;
use App\Support\CurrentSite;

// lrpDaily()/lrpQuery() are defined in LegacyRedirectPlannerTest.php (loaded suite-wide).

it('revives high-value unresolved legacy URLs as reviewable blog candidates', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    CurrentSite::set($site->id);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-installation', 'title' => 'Sump Pump Installation']);

    // Two unresolved informational URLs (no live equivalent): one above the floor, one below.
    lrpDaily($site, 'https://spg.example/sump-pump-alarm-troubleshooting/', 8000);
    lrpQuery($site, 'https://spg.example/sump-pump-alarm-troubleshooting/', 'sump pump alarm going off', 8000);
    lrpDaily($site, 'https://spg.example/obscure-thin-note/', 1000);

    $created = app(LegacyContentReviver::class)->revive($site, minImpressions: 5000, limit: 100);

    expect($created)->toHaveCount(1);
    $c = $created[0];
    expect($c->kind)->toBe(ContentKind::Post)
        ->and($c->status)->toBe(ContentStatus::Candidate)
        ->and($c->title)->toBe('Sump Pump Alarm Going Off')
        ->and($c->source_url)->toBe('/sump-pump-alarm-troubleshooting')
        ->and($c->meta['revived_from_url'])->toBe('/sump-pump-alarm-troubleshooting')
        ->and($c->meta['revived_impressions'])->toBe(8000)
        ->and($c->angle_hint)->toContain('sump pump alarm going off');

    // Below-floor URL was not revived.
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', ContentKind::Post->value)->count())->toBe(1);
});

it('is idempotent — a URL already revived is not re-created', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG2', 'domain_url' => 'https://spg2.example']);
    CurrentSite::set($site->id);

    lrpDaily($site, 'https://spg2.example/how-often-should-sump-pump-run/', 9000);
    lrpQuery($site, 'https://spg2.example/how-often-should-sump-pump-run/', 'how often should a sump pump run', 9000);

    $reviver = app(LegacyContentReviver::class);
    $reviver->revive($site, minImpressions: 5000);
    $second = $reviver->revive($site, minImpressions: 5000);

    expect($second)->toBe([])
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', ContentKind::Post->value)->count())->toBe(1);
});
