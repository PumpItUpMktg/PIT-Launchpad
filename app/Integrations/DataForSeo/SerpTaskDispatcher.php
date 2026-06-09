<?php

namespace App\Integrations\DataForSeo;

use App\Enums\SerpTaskState;
use App\Models\SerpTask;

/**
 * Posts standard-mode DataForSEO tasks while guarding against double-spend: it
 * never re-dispatches a task that is already pending for the same
 * (function × cache_key), so a refresh inside the cadence window can't post the
 * same SERP/maps pull twice.
 */
class SerpTaskDispatcher
{
    public function __construct(
        private readonly DataForSeoClient $client,
    ) {}

    /**
     * Ensure a standard-mode task exists for (function × cacheKey). Returns the
     * SerpTask (the existing pending one when deduped, else the newly posted one),
     * or null when no task could be posted.
     *
     * @param  array<string, mixed>  $taskPayload
     */
    public function ensure(string $function, string $cacheKey, string $postPath, array $taskPayload): ?SerpTask
    {
        $existing = SerpTask::query()
            ->where('function', $function)
            ->where('cache_key', $cacheKey)
            ->where('state', SerpTaskState::Pending->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $ids = $this->client->taskPost($postPath, [$taskPayload]);
        if ($ids === []) {
            return null;
        }

        return SerpTask::create([
            'function' => $function,
            'task_id' => $ids[0],
            'cache_key' => $cacheKey,
            'query' => (string) ($taskPayload['keyword'] ?? ''),
            'location_code' => $taskPayload['location_code'] ?? null,
            'language_code' => $taskPayload['language_code'] ?? null,
            'location_coordinate' => $taskPayload['location_coordinate'] ?? null,
            'state' => SerpTaskState::Pending,
        ]);
    }
}
