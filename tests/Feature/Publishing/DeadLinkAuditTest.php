<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Site;
use App\Publishing\Links\DeadLinkAudit;
use Illuminate\Support\Facades\Artisan;

function pubPage(Site $s, PageType $type, string $slug, ?string $body = null, array $slots = []): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Page, 'page_type' => $type,
        'status' => ContentStatus::Published, 'slug' => $slug, 'title' => $slug,
        'body' => $body, 'slot_payload' => $slots,
    ]);
}

it('counts a baked href with no page + no redirect as dead, and leaves a live-target href alone', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    // The real (nested) service page exists.
    pubPage($site, PageType::Service, 'sump-pump-maintenance/sump-pump-repair');
    // A page whose FAQ links the STALE flat path (dead) and the real nested path (live).
    pubPage($site, PageType::Location, 'hoboken-nj', slots: [
        'faq' => [['a' => '<a href="/sump-pump-repair">repair</a> and <a href="/sump-pump-maintenance/sump-pump-repair">real</a>']],
    ]);

    $r = app(DeadLinkAudit::class)->audit($site);

    expect($r['scanned'])->toBe(2)
        ->and($r['dead'])->toBe(1)
        ->and($r['by_target'])->toHaveKey('/sump-pump-repair')
        ->and($r['by_target']['/sump-pump-repair'])->toBe(1)
        ->and($r['samples'][0]['href'])->toBe('/sump-pump-repair');
});

it('treats a href covered by an active redirect as resolved (301, not 404)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    pubPage($site, PageType::Location, 'hoboken-nj', body: '<a href="/old-thing">x</a>');

    // Without a redirect it would be dead; with one it resolves.
    expect(app(DeadLinkAudit::class)->audit($site)['dead'])->toBe(1);

    Redirect::create(['site_id' => $site->id, 'from_url' => '/old-thing', 'to_url' => '/hoboken-nj', 'code' => 301, 'status' => 'active', 'source' => 'duplicate']);

    expect(app(DeadLinkAudit::class)->audit($site)['dead'])->toBe(0);
});

it('ignores external, anchor, mailto and tel links (only internal paths are scanned)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    pubPage($site, PageType::Location, 'hoboken-nj', body: '<a href="https://google.com">g</a> <a href="#top">t</a> <a href="mailto:a@b.com">m</a> <a href="tel:123">p</a>');

    $r = app(DeadLinkAudit::class)->audit($site);

    expect($r['scanned'])->toBe(0)->and($r['dead'])->toBe(0);
});

it('does not scan an UNPUBLISHED page', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Candidate, 'slug' => 'draft-town', 'title' => 'Draft',
        'body' => '<a href="/nowhere">x</a>', 'slot_payload' => [],
    ]);

    expect(app(DeadLinkAudit::class)->audit($site)['scanned'])->toBe(0);
});

it('command reports the dead count with top targets and a clean run', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    pubPage($site, PageType::Location, 'hoboken-nj', body: '<a href="/sump-pump-repair">x</a>');

    $code = Artisan::call('launchpad:report-dead-internal-links', ['--site' => $site->id]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('dead of')
        ->and($out)->toContain('Top dead targets')
        ->and($out)->toContain('/sump-pump-repair');

    $clean = Site::factory()->create(['brand_name' => 'CleanCo', 'domain_url' => 'https://clean.example']);
    pubPage($clean, PageType::Location, 'newark-nj', body: '<a href="/newark-nj">self</a>');

    $code = Artisan::call('launchpad:report-dead-internal-links', ['--site' => $clean->id]);
    expect($code)->toBe(0)->and(Artisan::output())->toContain('Clean — no dead internal links');
});
