<?php

namespace App\Jobs;

use App\Links\Outreach\ContactFinder;
use App\Models\LinkProspect;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Look for a contact address on one prospect's page on the worker (§ Links) — the "Find contact" button.
 * Two reads of the target site at most. A hand-entered address is never overwritten.
 */
class FindProspectContact implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $prospectId) {}

    public function uniqueId(): string
    {
        return $this->prospectId;
    }

    public function handle(ContactFinder $finder): void
    {
        $prospect = LinkProspect::withoutGlobalScope(SiteScope::class)->find($this->prospectId);
        if ($prospect === null) {
            return;
        }
        $found = $finder->find($prospect);
        $attrs = ['contact_candidates' => $found['candidates'], 'contact_checked_at' => now()];
        if ($prospect->contact_source !== 'manual' && $found['email'] !== null) {
            $attrs['contact_email'] = $found['email'];
            $attrs['contact_source'] = $found['source'];
        }
        $prospect->forceFill($attrs)->save();
    }
}
