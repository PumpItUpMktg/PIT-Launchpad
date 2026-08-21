<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\UrlInspection\IndexStatus;
use App\Models\Content;
use App\Models\Site;
use App\OpsConsole\PublishedContentBoard;
use App\Support\PublicUrl;

/** A published service page, plus any field overrides. */
function pillPage(Site $site, array $over = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'wp_post_id' => 1, 'title' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair',
    ], $over));
}

function pillFor(Site $site, Content $c): array
{
    return collect(app(PublishedContentBoard::class)->forSite($site->id)['service'])->firstWhere('id', $c->id)['index_pill'];
}

it('is grey (not submitted) when there is no index signal yet', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $c = pillPage($site);

    expect(pillFor($site, $c)['state'])->toBe('unsubmitted');
});

it('is yellow (submitted) once the page has been pinged to IndexNow', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $c = pillPage($site, ['indexnow_submitted_at' => now()]);

    expect(pillFor($site, $c)['state'])->toBe('submitted');
});

it('is green (indexed) once Google confirms the URL is indexed', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $c = pillPage($site);
    $url = (string) PublicUrl::forContent('https://apex.example', $c);

    app()->instance(IndexInspector::class, new class($url) implements IndexInspector
    {
        public function __construct(private string $url) {}

        public function connected(Site $site): bool
        {
            return true;
        }

        public function inspect(Site $site, string $u): ?IndexStatus
        {
            return $this->cached($site, $u);
        }

        public function cached(Site $site, string $u): ?IndexStatus
        {
            return $u === $this->url
                ? new IndexStatus(url: $u, state: IndexCoverageState::Indexed, coverageState: 'Submitted and indexed')
                : null;
        }
    });

    expect(pillFor($site, $c)['state'])->toBe('indexed');
});
