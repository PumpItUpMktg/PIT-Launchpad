<?php

use App\ContentEngine\Drafting\SlotLengthClamp;

it('returns an in-bounds value unchanged (idempotent)', function () {
    $value = 'Endless on-demand hot water, installed cleanly in a single visit.';

    expect(SlotLengthClamp::clamp($value, 220))->toBe($value)
        ->and(SlotLengthClamp::clamp('<p>Short body.</p>', 900))->toBe('<p>Short body.</p>');
});

it('clamps an over-length inline subhead to ≤ max at a word boundary (the 221>220 case)', function () {
    $value = str_repeat('word ', 60); // 300 chars
    $out = SlotLengthClamp::clamp($value, 220);

    expect(mb_strlen($out))->toBeLessThanOrEqual(220)
        ->and($out)->not->toEndWith(' ')          // trimmed at a boundary, no dangling space
        ->and($out)->toStartWith('word');
});

it('keeps whole <p> paragraphs while they fit, dropping the overflow', function () {
    $a = '<p>'.str_repeat('a', 200).'</p>';
    $b = '<p>'.str_repeat('b', 200).'</p>';
    $c = '<p>'.str_repeat('c', 200).'</p>';

    $out = SlotLengthClamp::clamp($a."\n".$b."\n".$c, 500);

    expect(mb_strlen($out))->toBeLessThanOrEqual(500)
        ->and($out)->toContain('aaaa')
        ->and($out)->toContain('bbbb')
        ->and($out)->not->toContain('cccc')       // third paragraph dropped
        ->and($out)->toEndWith('</p>');            // no dangling tag
});

it('truncates the first paragraph when even it overruns, re-wrapping in <p>', function () {
    $out = SlotLengthClamp::clamp('<p>'.str_repeat('longword ', 200).'</p>', 300);

    expect(mb_strlen($out))->toBeLessThanOrEqual(300)
        ->and($out)->toStartWith('<p>')
        ->and($out)->toEndWith('</p>');
});

it('prefers a sentence boundary when it keeps most of the budget', function () {
    $value = 'First sentence is here. Second sentence runs on and on well beyond the cap we allow for it.';
    $out = SlotLengthClamp::clamp($value, 40);

    expect(mb_strlen($out))->toBeLessThanOrEqual(40)
        ->and($out)->toBe('First sentence is here.');
});

it('never emits a dangling tag — inline markup is stripped when a cut would split it', function () {
    $value = 'Start of the copy '.str_repeat('x', 300).' <a href="/deep">link</a>';
    $out = SlotLengthClamp::clamp($value, 100);

    expect(mb_strlen($out))->toBeLessThanOrEqual(100)
        ->and($out)->not->toContain('<a')
        ->and($out)->not->toContain('href');
});
