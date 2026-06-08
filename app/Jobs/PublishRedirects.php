<?php

namespace App\Jobs;

use App\Models\Site;
use App\Publishing\PublishRedirectsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes a site's redirects to /redirects on the queue. Idempotent (the plugin
 * upserts by from_url).
 */
class PublishRedirects implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $siteId,
    ) {}

    public function handle(PublishRedirectsService $service): void
    {
        $site = Site::find($this->siteId);

        if ($site !== null) {
            $service->publish($site);
        }
    }
}
