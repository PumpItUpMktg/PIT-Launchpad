<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\Sentinel;

it('parses a single body block (the degenerate post case)', function () {
    $raw = Sentinel::block('body', '<p>Hi</p>')."\n".Sentinel::block('seo.title', 'T');

    expect(DraftCall::parse($raw))->toMatchArray(['body' => '<p>Hi</p>', 'seo' => ['title' => 'T']]);
});

it('keeps raw control characters (literal newlines/tabs) verbatim — no escaping to get wrong', function () {
    $body = "<p>Worried about your water heater?</p>\n<p>We fix it\ttoday.</p>";
    $raw = Sentinel::block('body', $body)."\n".Sentinel::block('seo.title', 'Same-Day Repair');

    $parsed = DraftCall::parse($raw);

    expect($parsed['body'])->toBe($body)
        ->and($parsed['seo']['title'])->toBe('Same-Day Repair');
});

it('preserves UTF-8 multibyte content', function () {
    $raw = Sentinel::block('body', "Café — déjà vu\nnext line");

    expect(DraftCall::parse($raw)['body'])->toBe("Café — déjà vu\nnext line");
});

it('the case the old JSON encoding could NOT survive: an unescaped quote inside the HTML now parses', function () {
    // The NerdWallet-class failure: an anchor with a literal double-quote (and a
    // stray brace) inside the body terminated the JSON string early and lost the
    // whole draft. Between sentinels there is nothing to escape — it round-trips.
    $body = '<p>See <a href="/rates">today\'s rates</a> — a {win} for you.</p>'."\n".'<p>Quote: "endless hot water".</p>';
    $raw = Sentinel::block('body', $body)."\n".Sentinel::block('seo.title', 'Rates');

    expect(DraftCall::parse($raw)['body'])->toBe($body);
});

it('extracts blocks despite surrounding prose, a preamble, and a stray non-marker <<<', function () {
    $raw = "Sure — here's the draft (note: <<< is fine in prose):\n\n"
        .Sentinel::block('body', '<p>x</p>')
        ."\n\nHope that helps!";

    expect(DraftCall::parse($raw))->toMatchArray(['body' => '<p>x</p>']);
});

it('per-marker salvage: a trailing unterminated block is dropped, the complete blocks survive', function () {
    // Every well-formed block parses independently; an incomplete tail block (no
    // closing marker) contributes nothing instead of poisoning the whole draft —
    // the per-marker property the JSON encoding lacked.
    $raw = Sentinel::block('body', '<p>survived</p>')."\n"
        .Sentinel::block('seo.title', 'Survived Title')."\n"
        .Sentinel::OPEN_PREFIX.'seo.slug'.Sentinel::OPEN_SUFFIX."\nunterminated-tail";

    $parsed = DraftCall::parse($raw);

    expect($parsed['body'])->toBe('<p>survived</p>')
        ->and($parsed['seo']['title'])->toBe('Survived Title')
        ->and($parsed['seo'])->not->toHaveKey('slug'); // the broken tail block contributed nothing
});

it('a response with no sentinel blocks yields an empty payload (surfaced as a draft failure)', function () {
    expect(DraftCall::parse('Sorry, I cannot help with that right now.'))->toBe([]);
});
