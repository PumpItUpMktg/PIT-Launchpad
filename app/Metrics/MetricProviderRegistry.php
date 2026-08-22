<?php

namespace App\Metrics;

use App\Metrics\Contracts\MetricProvider;

/**
 * The registry of metric providers (§ Client Dashboard v1), bound as a singleton. Providers register
 * themselves (in a service provider) under their key(); SyncSiteMetrics resolves by key. In PR 1 the
 * registry is empty — the GSC provider lands in PR 2, DataForSEO in PR 4.
 */
class MetricProviderRegistry
{
    /** @var array<string, MetricProvider> */
    private array $providers = [];

    public function register(MetricProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): ?MetricProvider
    {
        return $this->providers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /** @return array<string, MetricProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
