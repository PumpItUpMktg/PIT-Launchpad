<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\LinkFindingType;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Links\InternalLinkFixer;
use App\Publishing\Links\LinkFinding;
use Illuminate\Support\Facades\Bus;

function fxPage(Site $site, array $attrs = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'wp_post_id' => random_int(2, 9999),
    ], $attrs));
}

it('fixes an opportunity — links the named term into the source slot and re-publishes', function () {
    Bus::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    $siloA = Silo::factory()->create(['site_id' => $site->id]);
    $siloB = Silo::factory()->create(['site_id' => $site->id]);
    $kw = Keyword::create(['site_id' => $site->id, 'query' => 'sewer line repair', 'source' => KeywordSource::Seed, 'status' => 'candidate']);
    $dest = fxPage($site, ['title' => 'Sewer Line Repair', 'slug' => 'sewer-line-repair', 'page_type' => PageType::Service, 'silo_id' => $siloB->id, 'target_keyword_id' => $kw->id]);
    $source = fxPage($site, [
        'title' => 'Ejector Pumps', 'slug' => 'ejector-pumps', 'page_type' => PageType::Service, 'silo_id' => $siloA->id,
        'slot_payload' => ['body' => 'That is sewer line repair territory.'],
    ]);

    $result = app(InternalLinkFixer::class)->fix($site, new LinkFinding(LinkFindingType::Opportunity, (string) $source->id, '', '', '', (string) $dest->id));

    expect($result->applied)->toBeTrue()
        ->and($source->fresh()->slot_payload['body'])->toContain('<a href="/sewer-line-repair">sewer line repair</a>');
    Bus::assertDispatched(PublishContent::class, fn (PublishContent $j) => $j->contentId === (string) $source->id);
});

it('fixes a dead end — appends an outbound link to the topic hub and re-publishes', function () {
    Bus::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $hub = fxPage($site, ['title' => 'Sump Pumps', 'slug' => 'sump-pumps', 'page_type' => PageType::Hub, 'silo_id' => $silo->id]);
    $dead = fxPage($site, ['title' => 'Old Guide', 'slug' => 'old-guide', 'page_type' => PageType::Service, 'silo_id' => $silo->id, 'slot_payload' => ['body' => 'A short guide.']]);

    $result = app(InternalLinkFixer::class)->fix($site, new LinkFinding(LinkFindingType::DeadEnd, (string) $dead->id, '', '', '', (string) $hub->id));

    expect($result->applied)->toBeTrue()
        ->and($dead->fresh()->slot_payload['body'])->toContain('<a href="/sump-pumps">Sump Pumps</a>');
    Bus::assertDispatched(PublishContent::class, fn (PublishContent $j) => $j->contentId === (string) $dead->id);
});

it('fixes an orphan — adds an inbound link FROM the hub and re-publishes the hub', function () {
    Bus::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $hub = fxPage($site, ['title' => 'Sump Pumps', 'slug' => 'sump-pumps', 'page_type' => PageType::Hub, 'silo_id' => $silo->id, 'slot_payload' => ['intro' => 'All sump pump services.']]);
    $orphan = fxPage($site, ['title' => 'Battery Backup', 'slug' => 'battery-backup', 'page_type' => PageType::Service, 'silo_id' => $silo->id]);

    $result = app(InternalLinkFixer::class)->fix($site, new LinkFinding(LinkFindingType::Orphan, (string) $orphan->id, '', '', '', (string) $hub->id));

    expect($result->applied)->toBeTrue()
        ->and($hub->fresh()->slot_payload['intro'])->toContain('<a href="/battery-backup">Battery Backup</a>');
    Bus::assertDispatched(PublishContent::class, fn (PublishContent $j) => $j->contentId === (string) $hub->id);
});

it('skips a fix when there is no hub or home to link from', function () {
    Bus::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    $orphan = fxPage($site, ['title' => 'Lonely', 'slug' => 'lonely', 'page_type' => PageType::Utility]); // no silo, no home

    $result = app(InternalLinkFixer::class)->fix($site, new LinkFinding(LinkFindingType::Orphan, (string) $orphan->id, '', '', '', null));

    expect($result->applied)->toBeFalse()
        ->and($result->message)->toContain('by hand');
    Bus::assertNotDispatched(PublishContent::class);
});
