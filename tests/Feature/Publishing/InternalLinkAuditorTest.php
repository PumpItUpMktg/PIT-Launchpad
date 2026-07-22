<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\LinkFindingType;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Links\InternalLinkAuditor;
use App\Publishing\Links\LinkFinding;
use Illuminate\Support\Facades\Artisan;

function publishedPage(Site $site, array $attrs = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'status' => ContentStatus::Published,
        'wp_post_id' => random_int(2, 9999),
    ], $attrs));
}

/** @return array<string, list<LinkFinding>> findings grouped by type value */
function auditGrouped(Site $site): array
{
    $out = [];
    foreach (app(InternalLinkAuditor::class)->audit($site) as $f) {
        $out[$f->type->value][] = $f;
    }

    return $out;
}

it('flags a published page nothing links to as an orphan', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    $orphan = publishedPage($site, ['title' => 'Lonely Guide', 'slug' => 'lonely-guide', 'page_type' => PageType::Utility]);

    $orphans = auditGrouped($site)[LinkFindingType::Orphan->value] ?? [];

    expect(collect($orphans)->pluck('contentId'))->toContain((string) $orphan->id);
});

it('a nested hub + spoke link each other — neither is an orphan or a dead end', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $hub = publishedPage($site, ['title' => 'Sump Pumps', 'slug' => 'sump-pumps', 'page_type' => PageType::Hub, 'silo_id' => $silo->id]);
    $spoke = publishedPage($site, [
        'title' => 'Sump Pump Installation', 'slug' => 'sump-pumps/installation',
        'page_type' => PageType::Service, 'silo_id' => $silo->id, 'parent_content_id' => $hub->id,
    ]);

    $g = auditGrouped($site);
    $orphanIds = collect($g[LinkFindingType::Orphan->value] ?? [])->pluck('contentId');
    $deadIds = collect($g[LinkFindingType::DeadEnd->value] ?? [])->pluck('contentId');

    expect($orphanIds)->not->toContain((string) $spoke->id)
        ->and($deadIds)->not->toContain((string) $hub->id)
        ->and($deadIds)->not->toContain((string) $spoke->id);
});

it('flags a page that links to nothing as a dead end, suggesting its hub', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    // A standalone published page with no children, no silo, no body links.
    $dead = publishedPage($site, ['title' => 'About Us', 'slug' => 'about', 'page_type' => PageType::Utility, 'slot_payload' => ['intro' => 'We keep basements dry.']]);

    $deadEnds = auditGrouped($site)[LinkFindingType::DeadEnd->value] ?? [];

    expect(collect($deadEnds)->pluck('contentId'))->toContain((string) $dead->id);
});

it('surfaces an unlinked cross-silo mention of another page’s keyword as a link opportunity', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    $siloA = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sewage & Ejector Pumps']);
    $siloB = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sewer Lines']);

    // The destination lives in a DIFFERENT silo (so it isn't already a sibling-spine link).
    $kw = Keyword::create(['site_id' => $site->id, 'query' => 'sewer line repair', 'source' => KeywordSource::Seed, 'status' => 'candidate']);
    $dest = publishedPage($site, ['title' => 'Sewer Line Repair', 'slug' => 'sewer-line-repair', 'page_type' => PageType::Service, 'silo_id' => $siloB->id, 'target_keyword_id' => $kw->id]);

    // The source (other silo) NAMES "sewer line repair" but never links it — cross-silo juice left on the table.
    $source = publishedPage($site, [
        'title' => 'Sewage Ejector Pumps', 'slug' => 'sewage-ejector-pumps', 'page_type' => PageType::Service, 'silo_id' => $siloA->id,
        'slot_payload' => ['body' => 'A backed-up ejector pump often traces to a failing main line — that is sewer line repair territory.'],
    ]);

    $opps = auditGrouped($site)[LinkFindingType::Opportunity->value] ?? [];
    $match = collect($opps)->first(fn (LinkFinding $f): bool => $f->contentId === (string) $source->id);

    expect($match)->not->toBeNull()
        ->and($match->suggestedContentId)->toBe((string) $dest->id)
        ->and($match->suggestedLabel)->toBe('Sewer Line Repair');
});

it('does not raise an opportunity when the mention is already linked in the body', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    $siloA = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sewage & Ejector Pumps']);
    $siloB = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sewer Lines']);
    $kw = Keyword::create(['site_id' => $site->id, 'query' => 'sewer line repair', 'source' => KeywordSource::Seed, 'status' => 'candidate']);
    $dest = publishedPage($site, ['title' => 'Sewer Line Repair', 'slug' => 'sewer-line-repair', 'page_type' => PageType::Service, 'silo_id' => $siloB->id, 'target_keyword_id' => $kw->id]);

    // Same cross-silo mention, but the body already links it → no opportunity.
    $source = publishedPage($site, [
        'title' => 'Sewage Ejector Pumps', 'slug' => 'sewage-ejector-pumps', 'page_type' => PageType::Service, 'silo_id' => $siloA->id,
        'slot_payload' => ['body' => 'That is <a href="/sewer-line-repair">sewer line repair</a> territory.'],
    ]);

    $opps = auditGrouped($site)[LinkFindingType::Opportunity->value] ?? [];

    expect(collect($opps)->where('contentId', (string) $source->id)->where('suggestedContentId', (string) $dest->id))->toBeEmpty();
});

it('the audit-links command reports the gaps and changes nothing', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'SPG']);
    publishedPage($site, ['title' => 'Lonely Guide', 'slug' => 'lonely-guide', 'page_type' => PageType::Utility]);

    Artisan::call('launchpad:audit-links', ['site' => 'SPG']);
    $out = Artisan::output();

    expect($out)->toContain('internal-link audit')
        ->toContain('No inbound links')
        ->toContain('lonely-guide')
        ->toContain('Read-only — nothing changed');
});
