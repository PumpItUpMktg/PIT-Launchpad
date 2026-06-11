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
