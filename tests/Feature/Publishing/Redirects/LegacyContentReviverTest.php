<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\LegacyContentReviver;
use App\Support\CurrentSite;

// lrpDaily()/lrpQuery() are defined in LegacyRedirectPlannerTest.php (loaded suite-wide).

beforeEach(function () {
    config()->set('launchpad.legacy_revival.min_impressions', 5000);
    config()->set('launchpad.legacy_revival.divert_floor', 20000);
});

it('groups numbered families into one candidate, diverts high-value slug matches, and leaves low-value ones as redirects', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    CurrentSite::set($site->id);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-installation', 'title' => 'Sump Pump Installation']);

    // A numbered cost family (slug_overlap → installation pillar), total 55k ≥ divert floor → revived whole.
    lrpDaily($site, 'https://spg.example/sump-pump-installation-cost-breakdown-3/', 25000);
    lrpDaily($site, 'https://spg.example/sump-pump-installation-cost-breakdown-8/', 30000);
    // An unresolved informational article (no pillar), 8k ≥ floor → revived.
    lrpDaily($site, 'https://spg.example/sump-pump-alarm-troubleshooting/', 8000);
    lrpQuery($site, 'https://spg.example/sump-pump-alarm-troubleshooting/', 'sump pump alarm going off', 8000);
    // A low-value slug match (11k < divert floor, no unresolved) → stays a redirect, NOT revived.
    lrpDaily($site, 'https://spg.example/water-powered-sump-pump-installation/', 11000);

    $created = app(LegacyContentReviver::class)->revive($site, minImpressions: 5000);

    expect($created)->toHaveCount(2);

    $byQuery = collect($created)->keyBy(fn (Content $c): string => (string) $c->meta['revived_query']);

    // The cost family collapsed to ONE candidate carrying BOTH old URLs.
    $cost = collect($created)->first(fn (Content $c): bool => str_contains((string) $c->slug, 'sump-pump-installation-cost'));
    expect($cost->kind)->toBe(ContentKind::Post)
        ->and($cost->status)->toBe(ContentStatus::Candidate)
        ->and($cost->meta['revived_from_urls'])->toContain('/sump-pump-installation-cost-breakdown-3')
        ->and($cost->meta['revived_from_urls'])->toContain('/sump-pump-installation-cost-breakdown-8')
        ->and($cost->meta['revived_impressions'])->toBe(55000);

    // The unresolved alarm article revived on its own.
    expect($byQuery->has('sump pump alarm going off'))->toBeTrue();

    // The low-value slug match was NOT revived (no candidate claims it).
    $claimed = collect($created)->flatMap(fn (Content $c): array => $c->meta['revived_from_urls']);
    expect($claimed)->not->toContain('/water-powered-sump-pump-installation');
});

it('is idempotent — the planner skips a URL a revival candidate already claimed', function () {
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
