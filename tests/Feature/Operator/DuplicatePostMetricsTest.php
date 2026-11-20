<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\GscUrlQueryDaily;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Operator\Coverage\DuplicatePostMetrics;
use Illuminate\Support\Facades\Artisan;

function dupPost(Site $s, string $title, string $slug, string $status, ?string $publishedAt): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Post, 'page_type' => null,
        'status' => $status, 'title' => $title, 'slug' => $slug,
        'published_at' => $publishedAt, 'body' => '<p>x</p>',
    ]);
}

function dupGscDay(Site $s, string $url, string $date, int $impr, int $clicks, ?float $pos): void
{
    GscUrlDaily::create([
        'site_id' => $s->id, 'grain_hash' => hash('sha256', $url.'|'.$date), 'date' => $date,
        'url' => $url, 'impressions' => $impr, 'clicks' => $clicks, 'ctr' => 0, 'position' => $pos,
    ]);
}

it('groups a -N slug pair by its de-numbered base and attaches GSC to both sides', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $original = dupPost($site, '$2.2M Sewer Grants in Northern Chester County', 'sewer-grants-chester-what', 'published', '2026-08-01');
    $twin = dupPost($site, '$2.2M Sewer Grants in Northern Chester County', 'sewer-grants-chester-what-2', 'published', '2026-08-20');

    // The original earns; the -2 twin is dead. Blended position = (100*5 + 100*7)/200 = 6.0.
    dupGscDay($site, 'https://spg.example/sewer-grants-chester-what/', '2026-09-01', 100, 4, 5.0);
    dupGscDay($site, 'https://spg.example/sewer-grants-chester-what/', '2026-09-02', 100, 6, 7.0);
    dupGscDay($site, 'https://spg.example/sewer-grants-chester-what-2/', '2026-09-01', 3, 0, 40.0);

    $report = app(DuplicatePostMetrics::class)->report($site, 3650);

    expect($report)->toHaveCount(1);
    $g = $report[0];
    $members = collect($g['members'])->keyBy('content_id');

    expect($g['key'])->toBe('sewer-grants-chester-what')
        ->and($members[$original->id]['impressions'])->toBe(200)
        ->and($members[$original->id]['clicks'])->toBe(10)
        ->and($members[$original->id]['position'])->toBe(6.0)
        ->and($members[$original->id]['numbered'])->toBeFalse()
        ->and($members[$original->id]['top_impressions'])->toBeTrue()   // the earner
        ->and($members[$twin->id]['numbered'])->toBeTrue()
        ->and($members[$twin->id]['impressions'])->toBe(3)
        ->and($members[$twin->id]['top_impressions'])->toBeFalse()
        ->and($g['earner_id'])->toBe($original->id)
        ->and($g['age_keeper_id'])->toBe($original->id)               // oldest
        ->and($g['age_conflict'])->toBeFalse();                       // earner IS the oldest here
});

it('raises age_conflict when the -N twin is the earner and the original is dead (the Buckingham lesson)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $original = dupPost($site, 'Flooding Resources Hoboken', 'flooding-resources-hoboken', 'published', '2026-06-01'); // oldest, dead
    $twin = dupPost($site, 'Flooding Resources Hoboken', 'flooding-resources-hoboken-2', 'published', '2026-07-15');   // newer, earns

    dupGscDay($site, 'https://spg.example/flooding-resources-hoboken-2/', '2026-09-01', 500, 20, 3.0);
    dupGscDay($site, 'https://spg.example/flooding-resources-hoboken/', '2026-09-01', 1, 0, 90.0);

    $g = app(DuplicatePostMetrics::class)->report($site, 3650)[0];

    expect($g['age_keeper_id'])->toBe($original->id)   // age would keep the elder…
        ->and($g['earner_id'])->toBe($twin->id)        // …but the -2 twin holds the authority
        ->and($g['age_conflict'])->toBeTrue();         // so age picks wrong — flag it
});

it('does not surface a post with no twin', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    dupPost($site, 'How to Prevent Flooding', 'how-to-prevent-flooding', 'published', '2026-08-01');

    expect(app(DuplicatePostMetrics::class)->report($site))->toBe([]);
});

it('is published-only — an unpublished candidate twin is not a live duplicate', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    dupPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-maintenance-tips', 'published', '2026-08-01');
    dupPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-maintenance-tips-2', ContentStatus::Candidate->value, null);

    expect(app(DuplicatePostMetrics::class)->report($site))->toBe([]); // only one is live → no live pair
});

it('honors the GSC window — impressions outside --days are excluded', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    dupPost($site, 'Radon Test Tampering', 'radon-test-tampering', 'published', '2026-08-01');
    dupPost($site, 'Radon Test Tampering', 'radon-test-tampering-2', 'published', '2026-08-02');

    dupGscDay($site, 'https://spg.example/radon-test-tampering/', now()->subDays(2)->toDateString(), 50, 1, 4.0);    // in window
    dupGscDay($site, 'https://spg.example/radon-test-tampering/', now()->subDays(400)->toDateString(), 999, 9, 4.0); // out of window

    $g = app(DuplicatePostMetrics::class)->report($site, 28)[0];
    $original = collect($g['members'])->firstWhere('numbered', false);

    expect($original['impressions'])->toBe(50); // only the in-window row counted
});

it('command prints both sides, flags the earner, and warns on an age-conflict group', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $original = dupPost($site, 'Flooding Resources Hoboken', 'flooding-resources-hoboken', 'published', '2026-06-01');
    $twin = dupPost($site, 'Flooding Resources Hoboken', 'flooding-resources-hoboken-2', 'published', '2026-07-15');
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $twin->id, 'url' => 'https://spg.example/flooding-resources-hoboken-2/', 'url_normalized' => '/flooding-resources-hoboken-2', 'index_verdict' => 'PASS']);
    dupGscDay($site, 'https://spg.example/flooding-resources-hoboken-2/', now()->subDays(1)->toDateString(), 500, 20, 3.0);

    $code = Artisan::call('launchpad:report-duplicate-posts', ['--site' => $site->id]);
    $out = Artisan::output(); // fetch once — Symfony's buffered output empties on read

    expect($code)->toBe(0)
        ->and($out)->toContain('Flooding Resources Hoboken')
        ->and($out)->toContain('/flooding-resources-hoboken-2/')
        ->and($out)->toContain('← earns')
        ->and($out)->toContain('age-conflict')
        ->and($out)->toContain('index: indexed');
});

it('reports a clean tenant as a real "nothing" result', function () {
    $site = Site::factory()->create(['brand_name' => 'CleanCo', 'domain_url' => 'https://clean.example']);
    dupPost($site, 'Winter Prep Checklist', 'winter-prep-checklist', 'published', '2026-08-01');

    $code = Artisan::call('launchpad:report-duplicate-posts', ['--site' => $site->id]);

    expect($code)->toBe(0)->and(Artisan::output())->toContain('No live duplicate blog posts found.');
});

function dupGscQuery(Site $s, string $url, string $query, int $impr): void
{
    GscUrlQueryDaily::create([
        'site_id' => $s->id, 'grain_hash' => hash('sha256', $url.'|'.$query), 'date' => now()->subDay()->toDateString(),
        'url' => $url, 'query' => $query, 'country' => 'usa', 'device' => 'DESKTOP', 'impressions' => $impr, 'clicks' => 0, 'ctr' => 0, 'position' => 8.0,
    ]);
}

it('groups near-duplicates with different slugs by rarity-weighted title similarity, or by observed cannibalisation (the smaller post earns most of its impressions on shared queries); unrelated posts stay apart', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    // Same story, two slugs — the title pass alone catches it (every token shared).
    $ventA = dupPost($site, 'Sump Pump Vent: What It Does and Why It Matters', 'sump-pump-vent-what-it-does-and-why-it-matters', 'published', '2026-07-01');
    $ventB = dupPost($site, 'Sump Pump Vent: What It Is and Why It Matters', 'sump-pump-vent-what-it-is-and-why-it-matters', 'published', '2026-08-01');
    // Titles only part alike — grouped because the smaller post takes every impression on queries the other ranks for.
    $costA = dupPost($site, 'Sump Pump Renovation Cost: A Complete Homeowner Guide', 'sump-pump-renovation-cost-a-complete-homeowner-guide', 'published', '2026-07-05');
    $costB = dupPost($site, 'Sump Pump Renovation Cost: How Much Homeowners Pay', 'sump-pump-renovation-cost-how-much-homeowners-pay', 'published', '2026-09-01');
    dupGscQuery($site, 'https://spg.example/sump-pump-renovation-cost-a-complete-homeowner-guide/', 'sump pump renovation cost', 90);
    dupGscQuery($site, 'https://spg.example/sump-pump-renovation-cost-a-complete-homeowner-guide/', 'how to remodel sump pump', 4);
    dupGscQuery($site, 'https://spg.example/sump-pump-renovation-cost-a-complete-homeowner-guide/', 'sump pump pit makeover', 20);
    dupGscQuery($site, 'https://spg.example/sump-pump-renovation-cost-how-much-homeowners-pay/', 'sump pump renovation cost', 30);
    dupGscQuery($site, 'https://spg.example/sump-pump-renovation-cost-how-much-homeowners-pay/', 'how to remodel sump pump', 2);
    dupGscDay($site, 'https://spg.example/sump-pump-renovation-cost-a-complete-homeowner-guide/', now()->subDay()->toDateString(), 114, 0, 9.0);
    // Related topic, different story — no rare word in common, no shared queries: stays its own post.
    dupPost($site, 'Best Sump Pump Brands to Protect Your Basement', 'best-sump-pump-brands-to-protect-your-basement', 'published', '2026-06-01');
    // Alike only in the common words — not grouped.
    dupPost($site, 'Sump Pump Alarm Going Off: Causes and What to Do Now', 'sump-pump-alarm-going-off-causes', 'published', '2026-06-02');
    dupPost($site, 'Sump Pump Alarm Keeps Beeping at Night: Causes and Fixes', 'sump-pump-alarm-keeps-beeping', 'published', '2026-06-03');
    // Two posts that share THREE queries but the smaller one earns most of its impressions elsewhere — not cannibalising.
    $loud = dupPost($site, 'How Loud Is a Sump Pump and When Should You Worry', 'how-loud-is-a-sump-pump', 'published', '2026-06-04');
    $often = dupPost($site, 'How Often Should a Sump Pump Run and When to Worry', 'how-often-should-a-sump-pump-run', 'published', '2026-06-05');
    foreach (['sump pump runs every 30 seconds' => [5, 200], 'how long should a sump pump run' => [3, 150], 'how often should a sump pump run' => [2, 400]] as $q => [$a, $b]) {
        dupGscQuery($site, 'https://spg.example/how-loud-is-a-sump-pump/', $q, $a);
        dupGscQuery($site, 'https://spg.example/how-often-should-a-sump-pump-run/', $q, $b);
    }
    dupGscQuery($site, 'https://spg.example/how-loud-is-a-sump-pump/', 'how loud is a sump pump', 180);
    dupGscQuery($site, 'https://spg.example/how-loud-is-a-sump-pump/', 'sump pump noise', 60);

    $report = app(DuplicatePostMetrics::class)->report($site, 28);

    expect($report)->toHaveCount(2);
    $vent = collect($report)->first(fn (array $g): bool => in_array($ventA->id, array_column($g['members'], 'content_id'), true));
    expect($vent['kind'])->toBe('title')
        ->and(collect($vent['members'])->pluck('content_id')->sort()->values()->all())->toBe(collect([$ventA->id, $ventB->id])->sort()->values()->all())
        ->and($vent['signals'][0])->toBe('title: 1.00')
        ->and($vent['oversized'])->toBeFalse();

    $cost = collect($report)->first(fn (array $g): bool => in_array($costA->id, array_column($g['members'], 'content_id'), true));
    expect($cost['kind'])->toBe('title+queries')
        ->and(collect($cost['members'])->pluck('content_id')->sort()->values()->all())->toBe(collect([$costA->id, $costB->id])->sort()->values()->all())
        ->and($cost['signals'][1])->toContain('queries: 2 shared, 100% of the smaller post')->toContain('sump pump renovation cost')
        ->and($cost['earner_id'])->toBe($costA->id);

    expect(collect($report)->contains(fn (array $g): bool => in_array($loud->id, array_column($g['members'], 'content_id'), true)))->toBeFalse()
        ->and(collect($report)->contains(fn (array $g): bool => in_array($often->id, array_column($g['members'], 'content_id'), true)))->toBeFalse();

    expect(DuplicatePostMetrics::titleTokens('Sump Pump Design Ideas 2025: Smarter Setups'))->toBe(['sump', 'pump', 'design', 'idea', 'smarter', 'setup']);
});

it('never chains: a post joins a group only when it pairs with every member, and boilerplate titles do not make a pair', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    // Filler so the common words earn their low weight.
    foreach (['Radon Fan Installation Explained', 'Basement Waterproofing Membrane Options', 'Sewer Line Camera Inspection Explained', 'Crawl Space Dehumidifier Sizing', 'Downspout Extension Ideas for Wet Yards', 'French Drain Depth and Gravel'] as $i => $t) {
        dupPost($site, $t, 'filler-'.chr(97 + $i), 'published', '2026-05-01');
    }
    // A–B cannibalise, B–C cannibalise, A–C do not. Chaining would make one group of three.
    $a = dupPost($site, 'Sump Pump Float Switch Problems', 'sump-pump-float-switch-problems', 'published', '2026-06-01');
    $b = dupPost($site, 'Sump Pump Float Switch Fixes', 'sump-pump-float-switch-fixes', 'published', '2026-06-02');
    $c = dupPost($site, 'Sump Pump Float Switch Replacement Cost', 'sump-pump-float-switch-replacement-cost', 'published', '2026-06-03');
    foreach (['float switch stuck' => [40, 300], 'sump pump float switch problems' => [60, 200]] as $q => [$x, $y]) {
        dupGscQuery($site, 'https://spg.example/sump-pump-float-switch-problems/', $q, $x);
        dupGscQuery($site, 'https://spg.example/sump-pump-float-switch-fixes/', $q, $y);
    }
    foreach (['float switch replacement' => [100, 30], 'sump pump switch cost' => [80, 20]] as $q => [$x, $y]) {
        dupGscQuery($site, 'https://spg.example/sump-pump-float-switch-fixes/', $q, $x);
        dupGscQuery($site, 'https://spg.example/sump-pump-float-switch-replacement-cost/', $q, $y);
    }
    // Boilerplate: "what every homeowner should know" on six different stories — none of them pair.
    $oil = dupPost($site, 'Sump Pump Oil: What Every Homeowner Needs to Know', 'sump-pump-oil-what-every-homeowner-needs-to-know', 'published', '2026-06-10');
    $elec = dupPost($site, 'Sump Pump Electrical Requirements: What Every Homeowner Should Know', 'sump-pump-electrical-requirements-every-homeowner-should-know', 'published', '2026-06-11');
    foreach (['Sump Pump Codes: What Every Homeowner Should Know', 'Sump Pump Excavation: What Every Homeowner Should Know', 'Sump Pump Warranty: What Every Homeowner Should Know', 'Sump Pump Radon Mitigation: What Every Homeowner Should Know'] as $i => $t) {
        dupPost($site, $t, 'boiler-'.chr(97 + $i), 'published', '2026-06-12');
    }

    $report = app(DuplicatePostMetrics::class)->report($site, 28);

    expect($report)->toHaveCount(1);
    $members = collect($report[0]['members'])->pluck('content_id')->sort()->values()->all();
    // The stronger pair forms the group; the third post fails against one member and stays out.
    expect(count($members))->toBe(2)
        ->and(in_array($b->id, $members, true))->toBeTrue()
        ->and(in_array($oil->id, $members, true))->toBeFalse()
        ->and(in_array($elec->id, $members, true))->toBeFalse()
        ->and($report[0]['kind'])->toBe('title+queries');
    expect(DuplicatePostMetrics::weightedJaccard(['sump', 'pump', 'oil', 'what', 'every', 'homeowner', 'know'], ['sump', 'pump', 'electrical', 'requirement', 'what', 'every', 'homeowner', 'know'], ['sump' => 0.0, 'pump' => 0.0, 'what' => 0.1, 'every' => 0.1, 'homeowner' => 0.1, 'know' => 0.1, 'oil' => 1.6, 'electrical' => 1.6, 'requirement' => 1.6]))->toBeLessThan(0.1);
    // Weights that carry no information (every token in every title — a two-post site) fall back to the plain Jaccard.
    expect(DuplicatePostMetrics::weightedJaccard(['sump', 'pump', 'vent'], ['sump', 'pump', 'vent'], ['sump' => 0.0, 'pump' => 0.0, 'vent' => 0.0]))->toBe(1.0)
        ->and(DuplicatePostMetrics::weightedJaccard(['sump', 'pump', 'vent'], ['sump', 'pump', 'alarm'], ['sump' => 0.0, 'pump' => 0.0, 'vent' => 0.0, 'alarm' => 0.0]))->toBe(0.5);
});

it('flags a near group above the size cap as oversized — a chain the operator splits by hand', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    for ($i = 0; $i < 5; $i++) {
        dupPost($site, 'Flood Watch NJ: What It Means', 'flood-watch-nj-take-'.chr(97 + $i), 'published', '2026-06-0'.($i + 1));
    }
    $report = app(DuplicatePostMetrics::class)->report($site, 28);
    expect($report)->toHaveCount(1)->and($report[0]['members'])->toHaveCount(5)->and($report[0]['oversized'])->toBeTrue();
});
