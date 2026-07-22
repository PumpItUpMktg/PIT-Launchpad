<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\InternalLinks;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function ilPage(Site $site, array $attrs = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'wp_post_id' => random_int(2, 9999),
    ], $attrs));
}

it('lists internal-link findings and fixes one on approval', function () {
    Bus::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'SPG']);
    session(['guided_site_id' => $site->id]);
    $siloA = Silo::factory()->create(['site_id' => $site->id]);
    $siloB = Silo::factory()->create(['site_id' => $site->id]);
    $kw = Keyword::create(['site_id' => $site->id, 'query' => 'sewer line repair', 'source' => KeywordSource::Seed, 'status' => 'candidate']);
    $dest = ilPage($site, ['title' => 'Sewer Line Repair', 'slug' => 'sewer-line-repair', 'page_type' => PageType::Service, 'silo_id' => $siloB->id, 'target_keyword_id' => $kw->id]);
    $source = ilPage($site, [
        'title' => 'Ejector Pumps', 'slug' => 'ejector-pumps', 'page_type' => PageType::Service, 'silo_id' => $siloA->id,
        'slot_payload' => ['body' => 'That is sewer line repair territory.'],
    ]);

    $component = Livewire::test(InternalLinks::class);
    $opps = $component->instance()->findings['opportunity'] ?? [];
    expect(collect($opps)->pluck('content_id'))->toContain((string) $source->id);

    $component->call('fix', 'opportunity', (string) $source->id, (string) $dest->id);

    expect($source->fresh()->slot_payload['body'])->toContain('<a href="/sewer-line-repair">');
    Bus::assertDispatched(PublishContent::class, fn (PublishContent $j) => $j->contentId === (string) $source->id);
});

it('is operator-gated in the Operate group', function () {
    expect(InternalLinks::getNavigationGroup())->toBe('Operate')
        ->and(InternalLinks::getNavigationLabel())->toBe('Internal Links');
});
