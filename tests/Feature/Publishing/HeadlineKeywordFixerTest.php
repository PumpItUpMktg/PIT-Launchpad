<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Integrations\Claude\ClaudeClient;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Site;
use App\Publishing\Seo\HeadlineKeywordFixer;
use App\Publishing\Seo\KeywordUsageAuditor;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeClaudeClient;

function offTargetPage(Site $site, string $keyword, array $overrides = []): Content
{
    $kw = Keyword::create(['site_id' => $site->id, 'query' => $keyword, 'source' => KeywordSource::Seed, 'status' => 'candidate']);

    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'status' => ContentStatus::Published,
        'page_type' => PageType::Service,
        'target_keyword_id' => $kw->id,
        'wp_post_id' => 4242,
        'slug' => 'sump-pump-installation',
        'title' => 'Sump Pump Installation',
        'slot_payload' => ['hero_headline' => 'Keep Your Basement Dry', 'hero_subhead' => 'Fast, clean protection.'],
        'meta' => ['seo' => ['title' => 'Keep Your Basement Dry', 'meta_description' => 'A dry basement, guaranteed.']],
    ], $overrides));
}

function fixerWith(string $response): HeadlineKeywordFixer
{
    app()->instance(ClaudeClient::class, new FakeClaudeClient($response));

    return new HeadlineKeywordFixer(app(ClaudeClient::class));
}

it('finds pages whose H1 omits the keyword, and skips fully-healthy ones', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    offTargetPage($site, 'sump pump installation'); // H1 "Keep Your Basement Dry" → off-target
    offTargetPage($site, 'sump pump repair', [      // H1 + non-bare title both carry the keyword → skipped
        'slug' => 'sump-pump-repair',
        'slot_payload' => ['hero_headline' => 'Sump Pump Repair, Same Day'],
        'meta' => ['seo' => ['title' => 'Sump Pump Repair, Same Day', 'meta_description' => 'Sump pump repair fast.']],
    ]);

    $pages = fixerWith('{}')->offTargetPages($site);

    expect($pages)->toHaveCount(1)
        ->and($pages->first()->slug)->toBe('sump-pump-installation');
});

it('also picks up an over-optimized page whose title is only the keyword', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    offTargetPage($site, 'commercial pump services', [
        'slug' => 'commercial-pump-services',
        'slot_payload' => ['hero_headline' => 'Commercial Pump Services You Can Rely On'], // H1 fine
        'meta' => ['seo' => ['title' => 'Commercial Pump Services', 'meta_description' => 'Commercial pump services.']], // title is BARE
    ]);

    expect(fixerWith('{}')->offTargetPages($site))->toHaveCount(1);
});

it('rejects a bare-keyword title from the model and falls back to a non-bare title', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $page = offTargetPage($site, 'commercial pump services', [
        'slug' => 'commercial-pump-services',
        'title' => 'Commercial Pump Services',
        'slot_payload' => ['hero_headline' => 'We Keep Plants Running'], // H1 off-target → page is picked up
        'meta' => ['seo' => ['title' => 'Commercial Pump Services', 'meta_description' => 'Pumps.']],
    ]);

    // Model returns the bare keyword as the title — must be rejected.
    $fix = fixerWith(json_encode([
        'hero_headline' => 'Commercial Pump Services for Facilities',
        'seo_title' => 'Commercial Pump Services',
        'meta_description' => 'Commercial pump services for facilities and plants.',
    ]))->propose($page->fresh());

    expect(KeywordUsageAuditor::isBareKeyword('commercial pump services', $fix->newTitle))->toBeFalse()
        ->and(strtolower($fix->newTitle))->toContain('commercial pump services'); // still on-keyword, just not ONLY it
});

it('adopts a Haiku rewrite that leads with the keyword and fits the budget', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $page = offTargetPage($site, 'sump pump installation');

    $fixer = fixerWith(json_encode([
        'hero_headline' => 'Sump Pump Installation, Done in a Day',
        'seo_title' => 'Sump Pump Installation for a Dry Basement',
        'meta_description' => 'Professional sump pump installation that keeps your basement dry — clean, same-day work.',
    ]));

    $fix = $fixer->propose($page->fresh());

    expect($fix)->not->toBeNull()
        ->and(KeywordUsageAuditor::placement('sump pump installation', $fix->newH1))->toBe(KeywordUsageAuditor::EXACT)
        ->and($fix->newH1)->toBe('Sump Pump Installation, Done in a Day')
        ->and($fix->changed())->toBeTrue();
});

it('falls back to a keyword-led headline when the model output still omits the keyword', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $page = offTargetPage($site, 'sump pump installation');

    // Model ignored the instruction — no keyword. The fixer must not accept it.
    $fix = fixerWith(json_encode([
        'hero_headline' => 'The Best Basement Protection Around',
        'seo_title' => 'Basement Protection Experts',
        'meta_description' => 'We keep basements dry.',
    ]))->propose($page->fresh());

    expect(KeywordUsageAuditor::placement('sump pump installation', $fix->newH1))->not->toBe(KeywordUsageAuditor::ABSENT)
        ->and(strtolower($fix->newH1))->toContain('sump pump installation');
});

it('applies the fix onto the page and re-publishes (idempotent — date preserved)', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $page = offTargetPage($site, 'sump pump installation');
    $originalDate = $page->published_at;

    $fixer = fixerWith(json_encode([
        'hero_headline' => 'Sump Pump Installation, Done in a Day',
        'seo_title' => 'Sump Pump Installation for a Dry Basement',
        'meta_description' => 'Professional sump pump installation, clean and same-day.',
    ]));

    $fixer->apply($fixer->propose($page->fresh()), null);

    $fresh = $page->fresh();
    expect($fresh->slot_payload['hero_headline'])->toBe('Sump Pump Installation, Done in a Day')
        ->and($fresh->meta['seo']['title'])->toBe('Sump Pump Installation for a Dry Basement')
        ->and($fresh->published_at?->toDateTimeString())->toBe($originalDate?->toDateTimeString()); // untouched

    Queue::assertPushed(PublishContent::class);
});
