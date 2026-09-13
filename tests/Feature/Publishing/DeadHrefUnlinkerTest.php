<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Site;
use App\Publishing\Links\DeadHrefUnlinker;
use Illuminate\Support\Facades\Queue;

function unlinkPage(Site $s, string $slug, string $body = '', array $slots = [], ?int $wp = 501): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'slug' => $slug, 'title' => $slug, 'body' => $body,
        'slot_payload' => $slots, 'wp_post_id' => $wp,
    ]);
}

it('plans only anchors whose target has no live page and no redirect', function () {
    $site = Site::factory()->create();
    unlinkPage($site, 'trooper-pa/abington-pa');                                   // live
    Redirect::factory()->create(['site_id' => $site->id, 'from_url' => '/old-guide', 'to_url' => '/trooper-pa/abington-pa', 'status' => 'active']);
    $page = unlinkPage($site, 'piscataway',
        'See <a href="/fallston-md/3-bel-air-md">Bel Air</a>, <a href="/trooper-pa/abington-pa">Abington</a>, '
        .'<a href="/old-guide">the guide</a>, <a href="https://example.com/x">outside</a> and <a href="/fallston-md/3-bel-air-md">Bel Air again</a>.',
    );

    $plan = app(DeadHrefUnlinker::class)->plan($site);

    expect($plan['paths'])->toBe(['/fallston-md/3-bel-air-md'])
        ->and($plan['rows'])->toHaveCount(1)
        ->and($plan['rows'][0]['content_id'])->toBe((string) $page->id)
        ->and($plan['rows'][0]['anchors'])->toBe(2)
        ->and($plan['rows'][0]['sample'])->toBe('Bel Air')
        ->and($plan['pages'])->toBe(1)
        ->and($plan['anchors'])->toBe(2);
});

it('--execute unwraps the dead anchors (words kept), leaves live / redirected / external links, drops a dead Related line, and re-pushes once per page', function () {
    Queue::fake();
    $site = Site::factory()->create();
    unlinkPage($site, 'trooper-pa/abington-pa');
    $body = unlinkPage($site, 'piscataway',
        '<p>We also serve <a href="/fallston-md/3-bel-air-md">Bel Air</a> and <a href="/trooper-pa/abington-pa">Abington</a>.</p>'
        .' <p class="lp-related-inline">Related: <a href="/fallston-md/4-marshall-md">Marshall</a></p>',
    );
    $slots = unlinkPage($site, 'edison', '', [
        'intro' => 'Nearby: <a href="/fallston-md/3-bel-air-md"><strong>Bel Air</strong></a>.',
        'faq' => [['q' => 'Where?', 'a' => 'Also <a href="/downingtown-pa/aldan-pa">Aldan</a> and <a href="https://ext.test/">ext</a>.']],
    ]);
    $clean = unlinkPage($site, 'clean', '<a href="/trooper-pa/abington-pa">Abington</a>');
    $unpublished = unlinkPage($site, 'draft-only', '<a href="/fallston-md/3-bel-air-md">Bel Air</a>', [], null); // never pushed → no repush

    $this->artisan('launchpad:unlink-dead-hrefs', ['--site' => $site->id, '--execute' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Unlinked 5 anchor(s)');

    expect($body->fresh()->body)->toBe('<p>We also serve Bel Air and <a href="/trooper-pa/abington-pa">Abington</a>.</p>')
        ->and($slots->fresh()->slot_payload['intro'])->toBe('Nearby: <strong>Bel Air</strong>.')
        ->and($slots->fresh()->slot_payload['faq'][0]['a'])->toBe('Also Aldan and <a href="https://ext.test/">ext</a>.')
        ->and($clean->fresh()->body)->toBe('<a href="/trooper-pa/abington-pa">Abington</a>')
        ->and($unpublished->fresh()->body)->toBe('Bel Air');

    // One repush per changed page that is on WordPress — the clean page and the never-pushed one get none.
    Queue::assertPushed(PublishContent::class, 2);
    expect(app(DeadHrefUnlinker::class)->plan($site)['anchors'])->toBe(0); // idempotent — nothing left
});

it('report-only changes nothing', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $page = unlinkPage($site, 'piscataway', 'See <a href="/fallston-md/3-bel-air-md">Bel Air</a>.');

    $this->artisan('launchpad:unlink-dead-hrefs', ['--site' => $site->id])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only')
        ->expectsOutputToContain('would be unlinked');

    expect($page->fresh()->body)->toBe('See <a href="/fallston-md/3-bel-air-md">Bel Air</a>.');
    Queue::assertNothingPushed();
});
