<?php

use App\Analytics\Gsc\Grain;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\LegacyRedirectPlanner;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function cov404Daily(Site $site, string $url, int $impressions): void
{
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Grain::hash([$site->id, '2025-06-01', $url]),
        'date' => '2025-06-01',
        'url' => $url,
        'impressions' => $impressions,
        'clicks' => 0,
        'ctr' => 0,
        'position' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** A site with a few live pages the cascade + fallback route against. */
function cov404Site(): Site
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    CurrentSite::set($site->id);

    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair']);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-repair', 'title' => 'Sump Pump Repair', 'target_keyword_id' => $kw->id]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-installation', 'title' => 'Sump Pump Installation']);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'hoboken-nj', 'title' => 'Hoboken, NJ', 'page_type' => PageType::Location]);

    return $site;
}

it('covers every legacy url with a 301 — cascade match, else hub, else home', function () {
    $site = cov404Site();

    // Zero-impression 404s the impression-driven planner would never see.
    $plan = app(LegacyRedirectPlanner::class)->planCoverage($site, [
        'https://spg.example/hoboken/',                 // town → /hoboken-nj
        'https://spg.example/sump-pump-repair-cost/',   // strong slug overlap → /sump-pump-repair
        'https://spg.example/pump-maintenance-tips/',   // weak/ambiguous → fallback to closest hub
        'https://spg.example/check-valve-care/',        // nothing overlaps → fallback home
    ]);

    expect($plan['inputs'])->toBe(4)
        ->and($plan['redirect'])->toHaveCount(4)
        ->and($plan['skipped_live'])->toBe(0)
        ->and($plan['already'])->toBe(0);

    // Every row is a 301 — nothing is left to 404.
    expect(collect($plan['redirect'])->every(fn (array $r): bool => $r['code'] === 301))->toBeTrue();

    $to = collect($plan['redirect'])->keyBy('from')->map(fn (array $r): string => $r['to']);
    expect($to['/hoboken'])->toBe('/hoboken-nj')
        ->and($to['/sump-pump-repair-cost'])->toBe('/sump-pump-repair')
        ->and($to['/pump-maintenance-tips'])->toBe('/sump-pump-repair')  // hub fallback, shortest tied path
        ->and($to['/check-valve-care'])->toBe('/');                      // home fallback

    $reasons = collect($plan['redirect'])->keyBy('from')->map(fn (array $r): string => $r['reason']);
    expect($reasons['/hoboken'])->toBe('town')
        ->and($reasons['/sump-pump-repair-cost'])->toBe('slug_overlap')
        ->and($reasons['/pump-maintenance-tips'])->toBe('fallback_hub')
        ->and($reasons['/check-valve-care'])->toBe('fallback_home');
});

it('sends a blog archive with no page of its own to the blog index, a category that matches a live page to that page, and falls back as before when the site has no blog', function () {
    $site = cov404Site();
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'blog', 'title' => 'Blog', 'page_type' => PageType::Utility]);

    $plan = app(LegacyRedirectPlanner::class)->planCoverage($site, [
        '/category/backup-solutions/',                       // nothing of its own → the blog index
        '/tag/radon-testing/',                               // same
        '/category/sump-pump-installation/',                 // the category of a live page → that page
        '/category/installation-and-diy-tips/page/3/',       // a paged archive → the blog index
        '/feed/',
    ]);
    $to = collect($plan['redirect'])->keyBy('from');
    expect($to['/category/backup-solutions']['to'])->toBe('/blog')->and($to['/category/backup-solutions']['reason'])->toBe('archive_blog')
        ->and($to['/tag/radon-testing']['to'])->toBe('/blog')
        ->and($to['/category/sump-pump-installation']['to'])->toBe('/sump-pump-installation')
        ->and($to['/category/installation-and-diy-tips/page/3']['to'])->toBe('/blog')
        ->and($to['/feed']['to'])->toBe('/blog');
    expect(LegacyRedirectPlanner::isArchive('/category/x/'))->toBeTrue()
        ->and(LegacyRedirectPlanner::isArchive('/author/eric'))->toBeTrue()
        ->and(LegacyRedirectPlanner::isArchive('/sump-pump-repair/'))->toBeFalse();

    // Without a blog page the old fallback stands (closest hub, else home) — never a dead "/blog".
    $noBlog = cov404Site();
    $plan2 = app(LegacyRedirectPlanner::class)->planCoverage($noBlog, ['/category/backup-solutions/']);
    expect($plan2['redirect'][0]['to'])->not->toBe('/blog')->and($plan2['redirect'][0]['reason'])->toStartWith('fallback_');

    // The command takes the paths inline for a console with no file upload.
    CurrentSite::set($site->id);   // back to the first tenant (the second fixture locked its own)
    $this->artisan('launchpad:cover-legacy-404s --site='.$site->id.' --paths="/category/backup-solutions/, /tag/radon-testing/" --apply')
        ->expectsOutputToContain('2 path(s) typed inline.')
        ->assertSuccessful();
    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/category/backup-solutions')->value('to_url'))->toBe('/blog')
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/tag/radon-testing')->value('to_url'))->toBe('/blog');
});

it('unions the supplied 404 list with the GSC impression inventory', function () {
    $site = cov404Site();
    cov404Daily($site, 'https://spg.example/old-writeup/', 140); // in GSC, not in the list

    $plan = app(LegacyRedirectPlanner::class)->planCoverage($site, ['https://spg.example/check-valve-care/']);

    $froms = collect($plan['redirect'])->pluck('from');
    expect($froms)->toContain('/old-writeup')       // pulled from the inventory
        ->and($froms)->toContain('/check-valve-care'); // pulled from the list
    // The inventory URL carries its impressions through.
    expect(collect($plan['redirect'])->firstWhere('from', '/old-writeup')['impressions'])->toBe(140);
});

it('skips live pages and already-redirected urls, and applies idempotent 301 rows', function () {
    $site = cov404Site();
    Redirect::create(['site_id' => $site->id, 'from_url' => '/foo/', 'to_url' => '/sump-pump-repair', 'code' => 301, 'status' => 'active', 'source' => 'migration']);

    $planner = app(LegacyRedirectPlanner::class);
    $plan = $planner->planCoverage($site, [
        'https://spg.example/sump-pump-repair/',  // live → skipped
        'https://spg.example/foo/',               // already redirected → skipped
        'https://spg.example/check-valve-care/',  // new → 301
    ]);

    expect($plan['skipped_live'])->toBe(1)
        ->and($plan['already'])->toBe(1)
        ->and($plan['redirect'])->toHaveCount(1);

    $written = $planner->apply($site, $plan);
    expect($written)->toBe(1);

    $rows = Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(2); // the pre-existing /foo + the new /check-valve-care
    $care = $rows->firstWhere('from_url', '/check-valve-care');
    expect($care)->not->toBeNull()
        ->and((int) $care->code)->toBe(301);

    // Re-applying the same plan upserts, never duplicates.
    $planner->apply($site, $planner->planCoverage($site, ['https://spg.example/check-valve-care/']));
    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(2);
});
