<?php

use App\ContentEngine\Questions\QuestionCluster;
use App\ContentEngine\Questions\QuestionCoverage;
use App\ContentEngine\Questions\QuestionStat;
use App\ContentEngine\Questions\QuestionVerdict;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Models\Content;
use App\Models\Site;

beforeEach(function () {
    $this->app->instance(EmbeddingProvider::class, new MockEmbeddingProvider);
});

function questionClusterAt(string $query, ?string $url, ?float $position, int $impressions = 100): QuestionCluster
{
    $urls = $url !== null ? [$url => ['impressions' => $impressions, 'clicks' => 2, 'position' => $position]] : [];

    return new QuestionCluster(
        $lead = new QuestionStat($query, $impressions, 2, $position, $position, 10, '2026-08-01', '2026-10-30', 8, $urls),
        [$lead],
    );
}

it('is covered when our post ranks in the top slots, a refresh of that post when it ranks lower', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'how-long-do-sump-pumps-last', 'title' => 'How long do sump pumps last?', 'status' => ContentStatus::Published]);

    $coverage = app(QuestionCoverage::class);
    $covered = $coverage->verdict($site, questionClusterAt('how long do sump pumps last', 'https://www.acme.test/how-long-do-sump-pumps-last/', 3.0));
    $refresh = $coverage->verdict($site, questionClusterAt('how long do sump pumps last', 'https://acme.test/how-long-do-sump-pumps-last/', 12.4));

    expect($covered->kind)->toBe(QuestionVerdict::COVERED)
        ->and($covered->content?->id)->toBe($post->id)
        ->and($refresh->kind)->toBe(QuestionVerdict::REFRESH)
        ->and($refresh->content?->id)->toBe($post->id)
        ->and($refresh->position)->toBe(12.4)
        ->and($refresh->reason)->toContain('#12.4');
});

it('says author when a hub, the home page or a legacy URL carries the question and no post reads as it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    Content::factory()->page()->create(['site_id' => $site->id, 'slug' => 'doylestown-pa', 'page_type' => PageType::Hub, 'title' => 'Doylestown office', 'status' => ContentStatus::Published]);
    Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'winter-storm-prep', 'title' => 'Protect your basement from winter storm flooding', 'status' => ContentStatus::Published, 'body' => 'x']);

    $coverage = app(QuestionCoverage::class);
    $hub = $coverage->verdict($site, questionClusterAt('does a sump pump need a dedicated circuit', 'https://acme.test/doylestown-pa/', 6.0));
    $legacy = $coverage->verdict($site, questionClusterAt('does a sump pump need a dedicated circuit', 'http://www.acme.test/doylestown', 6.0));
    $none = $coverage->verdict($site, questionClusterAt('does a sump pump need a dedicated circuit', null, null));

    expect($hub->kind)->toBe(QuestionVerdict::AUTHOR)->and($hub->reason)->toContain('office hub')
        ->and($legacy->kind)->toBe(QuestionVerdict::AUTHOR)->and($legacy->reason)->toContain('outside the platform')
        ->and($none->kind)->toBe(QuestionVerdict::AUTHOR)->and($none->reason)->toBe('nothing of ours ranks for it');
});

it('turns author into a refresh when a live post reads as the same topic by title', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'sump-pump-lifespan', 'title' => 'How long do sump pumps last', 'status' => ContentStatus::Published, 'body' => 'x']);

    $verdict = app(QuestionCoverage::class)->verdict($site, questionClusterAt('how long do sump pumps last', 'https://acme.test/', 8.0));

    expect($verdict->kind)->toBe(QuestionVerdict::REFRESH)
        ->and($verdict->content?->id)->toBe($post->id)
        ->and($verdict->similarity)->toBeGreaterThan(0.7)
        ->and($verdict->reason)->toContain('the home page ranks for it');
});

it('never resolves another tenant\'s post', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $other = Site::factory()->create(['domain_url' => 'https://other.test']);
    Content::factory()->post()->create(['site_id' => $other->id, 'slug' => 'how-long-do-sump-pumps-last', 'title' => 'How long do sump pumps last?', 'status' => ContentStatus::Published, 'body' => 'x']);

    $verdict = app(QuestionCoverage::class)->verdict($site, questionClusterAt('how long do sump pumps last', 'https://acme.test/how-long-do-sump-pumps-last/', 3.0));

    expect($verdict->kind)->toBe(QuestionVerdict::AUTHOR);
});

it('resolves the slug WordPress rewrote — a numeric suffix or a category prefix — to our post', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'sump-pump-installation-cost-breakdown', 'title' => 'Sump pump installation cost breakdown', 'status' => ContentStatus::Published, 'body' => 'x']);
    Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'sump-pump-battery-backup-installation-cost', 'title' => 'Sump Pump Battery Backup Installation Cost', 'status' => ContentStatus::Published, 'body' => 'x']);

    $coverage = app(QuestionCoverage::class);
    $suffixed = $coverage->verdict($site, questionClusterAt('how much does it cost to install a sump pump', 'https://acme.test/sump-pump-installation-cost-breakdown-10/', 58.3));
    $prefixed = $coverage->verdict($site, questionClusterAt('sump pump installation cost', 'https://acme.test/blog/sump-pump-installation-cost-breakdown/', 59.8));

    expect($suffixed->kind)->toBe(QuestionVerdict::REFRESH)->and($suffixed->content?->id)->toBe($post->id)
        ->and($prefixed->kind)->toBe(QuestionVerdict::REFRESH)->and($prefixed->content?->id)->toBe($post->id);
});

it('does not take a post that merely shares a few words as the twin', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'sump-pump-battery-backup-installation-cost', 'title' => 'Sump Pump Battery Backup Installation Cost', 'status' => ContentStatus::Published, 'body' => 'x']);

    $verdict = app(QuestionCoverage::class)->verdict($site, questionClusterAt('how much does it cost to install a sump pump', 'https://acme.test/', 58.3));

    expect($verdict->kind)->toBe(QuestionVerdict::AUTHOR);
});
