<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use App\Integrations\Claude\AnthropicClaudeClient;
use Throwable;

/**
 * Claude — one minimal Haiku completion (the real Anthropic SDK path), mirroring
 * the §6a scoring call site (Haiku, no extended thinking).
 */
class ClaudeProbe implements VendorProbe
{
    public function label(): string
    {
        return 'Claude';
    }

    public function order(): int
    {
        return 10;
    }

    public function run(): ProbeResult
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            return ProbeResult::skip('ANTHROPIC_API_KEY not set');
        }

        $model = (string) config('services.anthropic.scoring_model', 'claude-haiku-4-5');

        try {
            $text = (new AnthropicClaudeClient($key, $model, 256, thinking: null))
                ->complete('Reply with the single word: ok');

            return trim($text) !== ''
                ? ProbeResult::live("model={$model} (scoring path, no thinking), completion returned")
                : ProbeResult::fail('completion was empty');
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }
}
