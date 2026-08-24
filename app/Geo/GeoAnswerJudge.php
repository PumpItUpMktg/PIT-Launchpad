<?php

namespace App\Geo;

use App\Integrations\AiSearch\AiAnswer;
use App\Integrations\Claude\ClaudeClient;

/**
 * Detects the brand's standing in one AI answer — a cheap (Haiku) pass that returns cited / position /
 * sentiment / competitors. `cited` is belt-and-suspenders: a deterministic domain-or-brand-name match OR
 * the model's judgment, so an obvious citation is never missed on a model miss. Position/sentiment/
 * competitors come from the model.
 */
class GeoAnswerJudge
{
    public function __construct(private readonly ClaudeClient $claude) {}

    public function judge(string $brand, ?string $domain, string $prompt, AiAnswer $answer): GeoVerdict
    {
        $matched = $this->matchesBrand($brand, $domain, $answer);
        $data = $this->parse($this->claude->complete($this->prompt($brand, $prompt, $answer), $this->system()));

        $cited = $matched || (bool) ($data['cited'] ?? false);
        $position = isset($data['position']) && is_numeric($data['position']) ? (int) $data['position'] : null;
        $competitors = array_values(array_filter(
            array_map(fn ($c): string => trim((string) $c), (array) ($data['competitors'] ?? [])),
            fn (string $c): bool => $c !== '',
        ));

        // A brand that isn't cited has no sentiment toward it — it's "absent". When cited, take the model's
        // read (positive/neutral/negative), defaulting to neutral.
        if (! $cited) {
            $sentiment = 'absent';
        } else {
            $sentiment = in_array($data['sentiment'] ?? '', ['positive', 'neutral', 'negative'], true)
                ? (string) $data['sentiment']
                : 'neutral';
        }

        return new GeoVerdict($cited, $cited ? $position : null, $sentiment, $competitors);
    }

    /** Deterministic citation signal: the brand's domain host appears in the citations, or its name in the prose. */
    private function matchesBrand(string $brand, ?string $domain, AiAnswer $answer): bool
    {
        $brand = trim(mb_strtolower($brand));
        if ($brand !== '' && str_contains(mb_strtolower($answer->text), $brand)) {
            return true;
        }

        $host = $domain !== null ? mb_strtolower((string) (parse_url($domain, PHP_URL_HOST) ?: $domain)) : '';
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host === '') {
            return false;
        }

        foreach ($answer->citationUrls() as $url) {
            $urlHost = preg_replace('/^www\./', '', (string) (parse_url($url, PHP_URL_HOST) ?: '')) ?? '';
            if ($urlHost !== '' && $urlHost === $host) {
                return true;
            }
        }

        return false;
    }

    private function prompt(string $brand, string $prompt, AiAnswer $answer): string
    {
        $citations = implode("\n", array_map(fn (array $c): string => '- '.$c['url'], $answer->citations)) ?: '(none)';

        return "An AI assistant was asked: \"{$prompt}\"\n\nIts answer:\n{$answer->text}\n\nCited sources:\n{$citations}\n\n"
            ."For the home-services brand \"{$brand}\", judge how it appears in this answer. "
            .'Return ONLY JSON: {"cited":true|false,"position":<1-based rank among recommended businesses|null>,'
            .'"sentiment":"positive|neutral|negative|absent","competitors":["<other businesses named>"]}. '
            .'cited is true only if THIS brand is named or its site cited. position is its rank among recommended '
            .'businesses (1 = first/most prominent), null if not ranked. competitors are OTHER businesses the answer names.';
    }

    private function system(): string
    {
        return 'You assess brand visibility in AI search answers. Return strict JSON only, no prose.';
    }

    /** @return array<string, mixed> */
    private function parse(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
