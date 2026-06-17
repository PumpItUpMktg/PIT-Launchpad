<?php

namespace App\Integrations\Census;

/**
 * TEMPORARY request-scoped collector for TIGERweb query diagnostics so the Locations page
 * can surface the raw request URL + outcome (the coverage-returns-0 case is otherwise only
 * in the server log). Registered as a singleton; {@see TigerwebGazetteer} records each
 * query, the page reads it after Compute. Remove with the rest of the Locations debug
 * readouts once coverage is confirmed.
 */
class TigerwebDebug
{
    /** @var list<array{layer: int, url: string, status: int, count: int, error: mixed}> */
    public array $queries = [];

    /**
     * @param  array{layer: int, url: string, status: int, count: int, error: mixed}  $entry
     */
    public function record(array $entry): void
    {
        $this->queries[] = $entry;
    }

    public function summary(): string
    {
        if ($this->queries === []) {
            return 'no TIGERweb calls recorded';
        }

        return implode(' | ', array_map(function (array $q): string {
            $err = ($q['error'] ?? null) !== null ? ' ERROR '.json_encode($q['error']) : '';

            return sprintf('layer %d: HTTP %d, %d features%s', $q['layer'], $q['status'], $q['count'], $err);
        }, $this->queries));
    }

    public function lastUrl(): ?string
    {
        $last = $this->queries[array_key_last($this->queries)] ?? null;

        return $last['url'] ?? null;
    }
}
