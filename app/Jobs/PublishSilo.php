<?php

namespace App\Jobs;

use App\Jobs\Concerns\OnPublishLane;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Publishing\PublishSiloService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes a silo's structure to /silo on the queue. Idempotent by ULID.
 */
class PublishSilo implements ShouldQueue
{
    use OnPublishLane;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $siloId,
    ) {
        $this->onPublishLane();
    }

    public function handle(PublishSiloService $service): void
    {
        $silo = Silo::withoutGlobalScope(SiteScope::class)->find($this->siloId);

        if ($silo !== null) {
            $service->publish($silo);
        }
    }
}
