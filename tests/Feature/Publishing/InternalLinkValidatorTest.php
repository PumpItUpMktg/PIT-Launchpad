<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Site;
use App\Publishing\Links\InternalLinkValidator;

function linkerPage(Site $s, string $body = '', array $slots = []): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::NeedsReview, 'slug' => 'linker-'.uniqid(), 'title' => 'Linker',
        'body' => $body, 'slot_payload' => $slots,
    ]);
}

function targetPage(Site $s, string $slug, ContentStatus $status): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => $status, 'slug' => $slug, 'title' => $slug, 'slot_payload' => [],
    ]);
}

it('flags a link to a path that matches no page and no redirect', function () {
    $site = Site::factory()->create();
    $c = linkerPage($site, '<a href="/totally-made-up">bad</a>');

    expect(app(InternalLinkValidator::class)->deadLinks($c))->toBe(['/totally-made-up']);
});

it('does NOT flag a link to an approved-but-unpublished page (it will go live as built)', function () {
    $site = Site::factory()->create();
    // Target exists but is only a CANDIDATE — the drafter is told to link targets that go live later.
    targetPage($site, 'water-heaters/tank-install', ContentStatus::Candidate);
    $c = linkerPage($site, '<a href="/water-heaters/tank-install">ok</a> <a href="/totally-made-up">bad</a>');

    expect(app(InternalLinkValidator::class)->deadLinks($c))->toBe(['/totally-made-up']); // only the hallucination
});

it('does NOT flag a link covered by an active redirect', function () {
    $site = Site::factory()->create();
    $c = linkerPage($site, '<a href="/old-flat">x</a>');

    expect(app(InternalLinkValidator::class)->deadLinks($c))->toBe(['/old-flat']); // dead without a redirect

    Redirect::create(['site_id' => $site->id, 'from_url' => '/old-flat', 'to_url' => '/new-nested', 'code' => 301, 'status' => 'active', 'source' => 'slug_change']);

    expect(app(InternalLinkValidator::class)->deadLinks($c->fresh()))->toBe([]); // now resolves
});

it('ignores external / anchor links and resolves the home path', function () {
    $site = Site::factory()->create();
    $c = linkerPage($site, '<a href="https://google.com">g</a> <a href="#top">t</a> <a href="/">home</a>');

    expect(app(InternalLinkValidator::class)->deadLinks($c))->toBe([]);
});

it('reads links baked into the slot payload (FAQ), not just the body', function () {
    $site = Site::factory()->create();
    $c = linkerPage($site, '', ['faq' => [['a' => '<a href="/sump-pump-repair">repair</a>']]]);

    expect(app(InternalLinkValidator::class)->deadLinks($c))->toBe(['/sump-pump-repair']);
});
