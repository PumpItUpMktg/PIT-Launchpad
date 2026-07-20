<?php

use App\ContentEngine\Linking\PostLinkInjector;

test('it wraps the first whole-word mention of a town in a link to its page', function () {
    $body = '<p>Heavy rain is expected across Trooper this week. Trooper homeowners should prepare.</p>';

    $result = (new PostLinkInjector)->inject($body, ['Trooper' => '/trooper'], null);

    // First mention linked, second left as plain text — one link per target, no stuffing.
    expect($result['body'])->toBe('<p>Heavy rain is expected across <a href="/trooper">Trooper</a> this week. Trooper homeowners should prepare.</p>')
        ->and($result['injected'])->toBe([['anchor' => 'Trooper', 'path' => '/trooper', 'kind' => 'location']]);
});

test('it matches whole words only — a substring never gets linked', function () {
    $body = '<p>The Springfields moved away; Springfield stayed dry.</p>';

    $result = (new PostLinkInjector)->inject($body, ['Springfield' => '/springfield'], null);

    expect($result['body'])->toBe('<p>The Springfields moved away; <a href="/springfield">Springfield</a> stayed dry.</p>');
});

test('it never wraps a mention that is already inside an anchor, and is idempotent on the path', function () {
    $body = '<p>See <a href="/trooper">Trooper</a> for details. Trooper again.</p>';

    $result = (new PostLinkInjector)->inject($body, ['Trooper' => '/trooper'], null);

    // The path is already present → skip entirely (no second link, no nesting).
    expect($result['body'])->toBe($body)
        ->and($result['injected'])->toBe([]);
});

test('the silo link is woven inline when its label appears in the body', function () {
    $body = '<p>Our sump pump crews stayed busy after the storm.</p>';

    $result = (new PostLinkInjector)->inject($body, [], ['label' => 'sump pump', 'path' => '/services/sump-pumps']);

    expect($result['body'])->toBe('<p>Our <a href="/services/sump-pumps">sump pump</a> crews stayed busy after the storm.</p>')
        ->and($result['injected'])->toBe([['anchor' => 'sump pump', 'path' => '/services/sump-pumps', 'kind' => 'silo']]);
});

test('the silo link is appended as a Related line only when its label never appears', function () {
    $body = '<p>The storm dumped two inches overnight.</p>';

    $result = (new PostLinkInjector)->inject($body, [], ['label' => 'Sump Pumps', 'path' => '/services/sump-pumps']);

    expect($result['body'])->toBe($body."\n".'<p>Related: <a href="/services/sump-pumps">Sump Pumps</a></p>')
        ->and($result['injected'][0]['kind'])->toBe('silo');
});

test('a body with no resolvable links is returned untouched', function () {
    $body = '<p>Nothing to link here.</p>';

    $result = (new PostLinkInjector)->inject($body, [], null);

    expect($result['body'])->toBe($body)
        ->and($result['injected'])->toBe([]);
});

test('special characters in the path are escaped in the emitted href', function () {
    $body = '<p>News from Trooper today.</p>';

    $result = (new PostLinkInjector)->inject($body, ['Trooper' => '/trooper?a=b&c=d'], null);

    expect($result['body'])->toContain('href="/trooper?a=b&amp;c=d"');
});
