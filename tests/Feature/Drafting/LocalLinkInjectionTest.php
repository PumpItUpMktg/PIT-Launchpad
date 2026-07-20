<?php

use App\ContentEngine\Drafting\DraftRequest;
use App\ContentEngine\Linking\InternalLinkResolver;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Market;
use App\Models\Service;
use App\Models\Silo;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

/** A live (published + wp_post_id + slug) pure-location page for a town. */
function liveLocationPage(string $siteId, string $title, string $slug): Content
{
    return Content::factory()->create([
        'site_id' => $siteId,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'primary_service_id' => null,
        'status' => ContentStatus::Published,
        'wp_post_id' => random_int(100, 999),
        'title' => $title,
        'slug' => $slug,
    ]);
}

test('the resolver links a drafted town only when a LIVE location page exists (name-tolerant match)', function () {
    ['site' => $site] = DraftingHarness::fixture();

    liveLocationPage($site->id, 'Trooper, PA', 'trooper');          // published → linkable, title carries the state
    liveLocationPage($site->id, 'Norristown', 'norristown')          // published but not named in the draft
        ->forceFill(['status' => ContentStatus::NeedsReview])->save(); // and flip it unpublished → must be excluded
    // A service page for the same town must NEVER be mistaken for the location page.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'primary_service_id' => Service::factory()->create(['site_id' => $site->id])->id,
        'status' => ContentStatus::Published, 'wp_post_id' => 5, 'title' => 'Sump Pumps in Trooper', 'slug' => 'trooper/sump-pumps',
    ]);

    $links = (new InternalLinkResolver)->locationLinks($site->id, ['Trooper', 'Norristown', 'Collegeville']);

    expect($links)->toBe(['Trooper' => '/trooper']); // only the live pure-location Trooper page
});

test('the resolver returns the silo pillar link only when the pillar page is live', function () {
    ['site' => $site] = DraftingHarness::fixture();

    $pillar = liveLocationPage($site->id, 'Sump Pumps', 'services/sump-pumps'); // reuse the live-page helper
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'pillar_content_id' => $pillar->id]);

    expect((new InternalLinkResolver)->siloPillarLink($silo->id))
        ->toBe(['label' => 'Sump Pumps', 'path' => '/services/sump-pumps']);

    // No pillar page → no link (never a dead link).
    $bare = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drainage', 'pillar_content_id' => null]);
    expect((new InternalLinkResolver)->siloPillarLink($bare->id))->toBeNull();
});

test('a locally-relevant reactive draft links its town to the live Trooper page and its silo pillar', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Trooper']);

    $townPage = liveLocationPage($site->id, 'Trooper', 'trooper');
    $pillar = liveLocationPage($site->id, 'Sump Pumps', 'sump-pumps');
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'pillar_content_id' => $pillar->id]);

    // The drafter names Trooper in the body and reports it in "towns".
    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'body' => '<p>Heavy rain hit Trooper this week; homeowners scrambled.</p>',
        'towns' => ['Trooper'],
    ]));

    $request = new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Post,
        intakeType: IntakeType::Reactive,
        trigger: DraftTrigger::News,
        siloId: $silo->id,
        title: 'Storm slams the area',
        sourceName: 'Local Tribune',
        localRelevance: true,
    );

    $content = DraftingHarness::engine($claude)->run($request)->content;

    // Geographic juice: the town mention is now a link to the live Trooper page.
    expect($content->body)->toContain('<a href="/trooper">Trooper</a>')
        // Topical juice: the silo pillar link (appended, since "Sump Pumps" isn't in the body).
        ->and($content->body)->toContain('href="/sump-pumps"')
        ->and($content->meta['internal_links'])->toContain(['anchor' => 'Trooper', 'path' => '/trooper', 'kind' => 'location'])
        ->and(collect($content->meta['internal_links'])->pluck('kind')->all())->toContain('silo');
});

test('a town with no live page is left as plain text (only known pages are linked)', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Collegeville']);

    // No location page for Collegeville exists.
    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'body' => '<p>Collegeville saw flooding overnight.</p>',
        'towns' => ['Collegeville'],
    ]));

    $request = new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Post,
        intakeType: IntakeType::Reactive,
        trigger: DraftTrigger::News,
        title: 'Flood watch',
        sourceName: 'Wire',
        localRelevance: true,
    );

    $content = DraftingHarness::engine($claude)->run($request)->content;

    expect($content->body)->toBe('<p>Collegeville saw flooding overnight.</p>')
        ->and($content->meta['internal_links'])->toBe([]);
});
