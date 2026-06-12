<?php

use App\Models\Content;
use App\Models\Site;
use App\Publishing\MetaBlobAssembler;
use Illuminate\Support\Collection;

it('carries a post body to WordPress as the body slot (so it renders and mirrors to lp_slot_body)', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create([
        'site_id' => $site->id,
        'body' => '<p>Worried about your water heater? Here is the fix.</p>',
    ]);

    $payload = app(MetaBlobAssembler::class)->assemble($post, new Collection);

    // The body is delivered as the `body` slot — the plugin mirrors scalar slots
    // to lp_slot_body and [lp_slot key="body"] renders it. (Was: never sent.)
    expect($payload['slot_payload']['body'])->toBe('<p>Worried about your water heater? Here is the fix.</p>');
});

it('does not invent a body slot for a page (kit slots pass through unchanged)', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create([
        'site_id' => $site->id,
        'slot_payload' => ['hero_problem' => 'No hot water'],
        'body' => null,
    ]);

    $payload = app(MetaBlobAssembler::class)->assemble($page, new Collection);

    expect($payload['slot_payload'])->toBe(['hero_problem' => 'No hot water'])
        ->and($payload['slot_payload'])->not->toHaveKey('body');
});

it('strips a leading <h1> from the post body (the Post Title widget renders it — no duplicate)', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create([
        'site_id' => $site->id,
        'body' => "<h1>Same-Day Water Heater Repair</h1>\n<p>Worried about your water heater?</p><h2>Signs</h2>",
    ]);

    $payload = app(MetaBlobAssembler::class)->assemble($post, new Collection);

    expect($payload['slot_payload']['body'])->toBe('<p>Worried about your water heater?</p><h2>Signs</h2>')
        ->and($payload['slot_payload']['body'])->not->toContain('<h1');
});

it('strips the article <h1> even when wrapped (post 181: <article><h1>…)', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create([
        'site_id' => $site->id,
        'body' => "<article>\n <h1>Same-Day Water Heater Repair</h1>\n<p>Worried?</p></article>",
    ]);

    $body = app(MetaBlobAssembler::class)->assemble($post, new Collection)['slot_payload']['body'];

    expect($body)->not->toContain('<h1')
        ->and($body)->toContain('<article>')   // the wrapper is preserved
        ->and($body)->toContain('<p>Worried?</p>');
});

it('strips placeholder/citation/annotation tokens from the body (post 174: <sup>[review]</sup>)', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create([
        'site_id' => $site->id,
        'body' => '<p>We stand behind our work<sup>[review]</sup> with a written warranty [warranty].</p>',
    ]);

    $body = app(MetaBlobAssembler::class)->assemble($post, new Collection)['slot_payload']['body'];

    expect($body)->not->toContain('<sup')
        ->and($body)->not->toContain('[review]')
        ->and($body)->not->toContain('[warranty]')
        ->and($body)->toContain('written warranty'); // the real prose survives
});

it('leaves a body with no leading <h1> unchanged (idempotent) and keeps a deeper <h1>', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create([
        'site_id' => $site->id,
        'body' => '<p>Intro paragraph.</p><h1>Deep heading stays</h1>',
    ]);

    $payload = app(MetaBlobAssembler::class)->assemble($post, new Collection);

    expect($payload['slot_payload']['body'])->toBe('<p>Intro paragraph.</p><h1>Deep heading stays</h1>');
});

it('does not synthesize a kit for a post (no page-page → no lp-kit-page-page body class)', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'body' => '<p>x</p>']);

    $payload = app(MetaBlobAssembler::class)->assemble($post, new Collection);

    expect($payload['kit'])->toBe('');
});

it('leaves an explicit body slot untouched (never double-writes)', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create([
        'site_id' => $site->id,
        'slot_payload' => ['body' => '<p>Already a slot.</p>'],
        'body' => '<p>The column body.</p>',
    ]);

    $payload = app(MetaBlobAssembler::class)->assemble($post, new Collection);

    expect($payload['slot_payload']['body'])->toBe('<p>Already a slot.</p>');
});
