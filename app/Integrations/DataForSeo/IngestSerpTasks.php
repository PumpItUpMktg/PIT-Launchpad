<?php

namespace App\Integrations\DataForSeo;

use App\Enums\SerpTaskState;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\Models\SerpTask;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Standard-mode ingest sweep. For each endpoint family with pending tasks it
 * asks DataForSEO which tasks are ready (`tasks_ready`), collects each ready
 * task's result (`task_get`), parses it with the shared client parsers, writes
 * the normalized payload into the same cache key the live path uses (so the
 * provider's next read is a cache hit), and marks the task ingested.
 *
 * Failures are surfaced, never swallowed: an errored collection marks the task
 * `failed` with the message, and a task that never produced a result within the
 * expiry window is marked `failed` ("expired"). Both states are retained on the
 * SerpTask row for §5 / operators to see.
 */
class IngestSerpTasks implements ShouldQueue
{
    use Queueable;

    /** Pending tasks older than this (hours) with no result are expired. */
    private const EXPIRY_HOURS = 24;

    /**
     * function => [tasks_ready path, task_get path].
     *
     * @var array<string, array{ready: string, get: string}>
     */
    private const FAMILIES = [
        'organic' => [
            'ready' => '/v3/serp/google/organic/tasks_ready',
            'get' => '/v3/serp/google/organic/task_get/advanced',
        ],
        'maps' => [
            'ready' => '/v3/serp/google/maps/tasks_ready',
            'get' => '/v3/serp/google/maps/task_get/advanced',
        ],
    ];

    public function handle(DataForSeoClient $client, Cache $cache): void
    {
        $ttl = (int) config('services.dataforseo.cache_ttl_hours', 168) * 3600;

        foreach (self::FAMILIES as $function => $paths) {
            $pending = SerpTask::query()
                ->where('function', $function)
                ->where('state', SerpTaskState::Pending->value)
                ->whereNotNull('task_id')
                ->get();

            if ($pending->isEmpty()) {
                continue;
            }

            $this->expireStale($pending);

            $ready = array_flip($client->tasksReady($paths['ready']));

            foreach ($pending as $task) {
                if ($task->state !== SerpTaskState::Pending) {
                    continue; // expired above
                }
                if (! isset($ready[(string) $task->task_id])) {
                    continue; // not ready yet — leave pending for the next sweep
                }

                $this->collect($client, $cache, $task, $function, $paths['get'], $ttl);
            }
        }
    }

    private function collect(DataForSeoClient $client, Cache $cache, SerpTask $task, string $function, string $getPath, int $ttl): void
    {
        try {
            $result = $client->taskGet($getPath, (string) $task->task_id);

            $cache->put($task->cache_key, $this->normalize($function, $task->query, $result), $ttl);

            $task->update(['state' => SerpTaskState::Ingested, 'error' => null]);
        } catch (Throwable $e) {
            // Surface loudly on the row — never silently drop a failed pull.
            $task->update([
                'state' => SerpTaskState::Failed,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }

    /**
     * Normalize a collected result into what the provider's read path expects:
     * a SerpResultSet for organic, a parsed maps-item list for a grid cell.
     *
     * @param  array<int, mixed>  $result
     * @return SerpResultSet|list<array{rank: int|null, name: string, domain: string|null}>
     */
    private function normalize(string $function, string $query, array $result): SerpResultSet|array
    {
        if ($function === 'maps') {
            return DataForSeoClient::parseMaps($result);
        }

        $items = array_map(
            fn (array $i) => new SerpResult($i['position'], $i['url'], $i['domain']),
            DataForSeoClient::parseOrganic($result),
        );

        return new SerpResultSet($query, $items);
    }

    /**
     * @param  iterable<SerpTask>  $pending
     */
    private function expireStale(iterable $pending): void
    {
        $cutoff = now()->subHours(self::EXPIRY_HOURS);

        foreach ($pending as $task) {
            if ($task->created_at !== null && $task->created_at->lt($cutoff)) {
                $task->update([
                    'state' => SerpTaskState::Failed,
                    'error' => 'expired: no result within '.self::EXPIRY_HOURS.'h window',
                ]);
            }
        }
    }
}
