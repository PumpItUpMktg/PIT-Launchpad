<?php

namespace App\Jobs;

use App\Links\LinkProspector;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Run the competitor link intersect for one site on the worker (§ Links) — the "Find prospects" button.
 * One index call; unique per site while queued so a double click never doubles the spend.
 */
class FindLinkProspects implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(LinkProspector $prospector): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site !== null) {
            $prospector->run($site);
        }
    }
}
