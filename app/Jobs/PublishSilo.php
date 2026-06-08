<?php

namespace App\Jobs;

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
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $siloId,
    ) {}

    public function handle(PublishSiloService $service): void
    {
        $silo = Silo::withoutGlobalScope(SiteScope::class)->find($this->siloId);

        if ($silo !== null) {
            $service->publish($silo);
        }
    }
}
