<?php

use App\ContentEngine\Drafting\DraftCall;

it('parses a clean fenced JSON draft', function () {
    $raw = "```json\n{\"body\": \"<p>Hi</p>\", \"seo\": {\"title\": \"T\"}}\n```";

    expect(DraftCall::parse($raw))->toMatchArray(['body' => '<p>Hi</p>', 'seo' => ['title' => 'T']]);
});

it('repairs RAW control characters (literal newlines/tabs) inside the HTML body string', function () {
    // The reported shape: a complete, end_turn, fenced response whose body string
    // carries literal newlines + a tab — strict JSON forbids them, so a naive
    // json_decode fails even though the content is whole.
    $body = "<p>Worried about your water heater?</p>\n<p>We fix it\ttoday.</p>";
    $raw = "```json\n{\"body\": \"{$body}\", \"seo\": {\"title\": \"Same-Day Repair\"}}\n```";

    $parsed = DraftCall::parse($raw);

    expect($parsed['body'])->toBe($body) // control chars preserved as real text
        ->and($parsed['seo']['title'])->toBe('Same-Day Repair');
});

it('preserves UTF-8 multibyte content while repairing control chars', function () {
    $raw = "{\"body\": \"Café — déjà vu\nnext line\"}";

    expect(DraftCall::parse($raw)['body'])->toBe("Café — déjà vu\nnext line");
});

it('still extracts JSON despite prose (with a brace) before the fence and trailing text after', function () {
    $raw = "Sure, using a {key:value} shape:\n```json\n{\"body\":\"<p>x</p>\"}\n```\nHope that helps!";

    expect(DraftCall::parse($raw))->toMatchArray(['body' => '<p>x</p>']);
});

it('an UNescaped quote inside the HTML is not heuristically repairable (needs structured output)', function () {
    // Documents the known limit the control-char repair cannot fix: an unescaped
    // double-quote inside the body terminates the JSON string early. This is the
    // case the structured-output / sentinel-delimiting change is meant to retire.
    $raw = '{"body": "<a href="/x">link</a>", "seo": {"title": "T"}}';

    expect(DraftCall::parse($raw))->toBe([]); // surfaced as a draft failure, not a silent bad parse
});
