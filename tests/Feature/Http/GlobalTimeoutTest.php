<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/** Read the PendingRequest's protected Guzzle option bag. */
function pendingOptions(PendingRequest $request): array
{
    return (fn () => $this->options)->call($request);
}

test('a bare Http call inherits the global 15s timeout and 5s connect timeout without the author setting either', function () {
    // Built the way integration clients build it (the shared Http Factory singleton).
    $options = pendingOptions(app(Factory::class)->accept('application/json'));

    expect($options['timeout'] ?? null)->toBe(15)
        ->and($options['connect_timeout'] ?? null)->toBe(5);
});

test('an explicit per-call ->timeout() overrides the global total but still inherits the 5s connect timeout', function () {
    $options = pendingOptions(app(Factory::class)->timeout(60));

    expect($options['timeout'])->toBe(60)                 // the justified longer clients keep their total
        ->and($options['connect_timeout'] ?? null)->toBe(5); // and still get a bounded connect
});
