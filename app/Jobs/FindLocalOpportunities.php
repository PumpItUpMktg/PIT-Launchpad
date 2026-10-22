<?php

namespace App\Jobs;

use App\Links\LocalOpportunityFinder;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The Link prospects page's "Find local opportunities" button — the discovery pass, off the request. */
class FindLocalOpportunities implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(LocalOpportunityFinder $finder): void
    {
        $site = Site::find($this->siteId);
        if ($site !== null) {
            $finder->run($site);
        }
    }
}
