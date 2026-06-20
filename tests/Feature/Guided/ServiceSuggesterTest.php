<?php

use App\Guided\ServiceSuggester;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;

function bindClaude(string $reply, bool $throw = false): void
{
    app()->instance(ClaudeClient::class, new class($reply, $throw) implements ClaudeClient
    {
        public function __construct(private string $reply, private bool $throw) {}

        public function complete(string $prompt, ?string $system = null): string
        {
            if ($this->throw) {
                throw new RuntimeException('boom');
            }

            return $this->reply;
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            throw new BadMethodCallException('unused');
        }
    });
}

test('it suggests connecting services, excludes the already-stated, and caps to the limit', function () {
    bindClaude(json_encode([
        ['name' => 'Battery backup sump pumps', 'why' => 'runs during outages'],
        ['name' => 'Sump pump repair', 'why' => 'already offered'], // excluded (stated)
        ['name' => 'French drain installation', 'why' => 'pairs with waterproofing'],
        ['name' => 'Crawl space encapsulation', 'why' => 'same water problem'],
    ]));

    $out = app(ServiceSuggester::class)->suggest('Basement waterproofing', ['Sump pump repair'], limit: 2);

    expect($out)->toHaveCount(2)
        ->and($out[0]['name'])->toBe('Battery backup sump pumps')
        ->and(collect($out)->pluck('name'))->not->toContain('Sump pump repair');
});

test('a blank trade yields no suggestions (no model call needed)', function () {
    bindClaude('[]');

    expect(app(ServiceSuggester::class)->suggest('', ['x']))->toBe([]);
});

test('it tolerates fenced / prose-wrapped JSON', function () {
    bindClaude("Here you go:\n```json\n[{\"name\":\"Dry well installation\",\"why\":\"drainage\"}]\n```");

    $out = app(ServiceSuggester::class)->suggest('Drainage', []);

    expect($out)->toHaveCount(1)->and($out[0]['name'])->toBe('Dry well installation');
});

test('a model failure degrades to an empty list, never throws', function () {
    bindClaude('', throw: true);

    expect(app(ServiceSuggester::class)->suggest('Plumbing', []))->toBe([]);
});
