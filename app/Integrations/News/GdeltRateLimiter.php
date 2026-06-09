<?php

namespace App\Integrations\News;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Cache-backed inter-request throttle for GDELT. GDELT publishes no hard quota
 * but throttles abuse, so the community standard is ~1 request / 5-6s. A shared
 * cache slot (reserved under an atomic lock) keeps concurrent queued workers from
 * blowing past the interval — not just a single in-process sleep.
 */
class GdeltRateLimiter
{
    private const NEXT_KEY = 'gdelt:throttle:next';

    private const LOCK_KEY = 'gdelt:throttle:lock';

    public function __construct(
        private readonly Cache $cache,
        private readonly int $intervalSeconds,
    ) {}

    /**
     * Block until the next request is allowed, then reserve the following slot.
     */
    public function throttle(): void
    {
        if ($this->intervalSeconds <= 0) {
            return;
        }

        // Reserve the slot atomically so parallel workers serialize cleanly.
        $store = $this->cache->getStore();
        $lock = $store instanceof LockProvider ? $store->lock(self::LOCK_KEY, 10) : null;

        $lock?->block(10);

        try {
            $now = microtime(true);
            $nextAllowed = (float) $this->cache->get(self::NEXT_KEY, 0.0);

            if ($nextAllowed > $now) {
                usleep((int) (($nextAllowed - $now) * 1_000_000));
                $now = microtime(true);
            }

            $this->cache->put(self::NEXT_KEY, $now + $this->intervalSeconds, now()->addHour());
        } finally {
            $lock?->release();
        }
    }
}
