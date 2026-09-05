<?php

namespace App\Publishing\Chrome;

use App\Console\Commands\CheckStaleChromeCommand;
use App\Enums\SiteStatus;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Observers\ContentObserver;
use App\Publishing\ConnectionGate;
use Illuminate\Support\Facades\DB;

/**
 * Detects chrome DRIFT: a site whose live header/footer (the last profile pushed to WordPress, fingerprinted
 * in `chrome_synced_hash`) no longer matches what {@see SiteProfileAssembler} would assemble now. The live
 * chrome is a one-time push — a page publish/unpublish, NAP edit, or nav-order change alters what the menu
 * SHOULD contain, but nothing re-pushes it, so it drifts silently. This is the detector, purely advisory:
 * it persists the `sites.chrome_stale` flag; it NEVER pushes chrome.
 *
 * Maintained event-driven by {@see ContentObserver} on a page publish/unpublish, and
 * wholesale by {@see CheckStaleChromeCommand} (the weekly backstop, for the drift that
 * isn't a page publish). Writes go through the query builder so they bypass SiteScope (this runs outside a
 * tenant request) and never re-fire model events. Never-synced (chrome_synced_at null) is a SEPARATE signal,
 * not drift — {@see neverSynced}.
 */
class ChromeStaleness
{
    public function __construct(
        private readonly SiteProfileAssembler $assembler,
        private readonly ConnectionGate $connections,
    ) {}

    /** Recompute + persist the drift flag for one site (the event-driven single-site path). */
    public function recompute(string $siteId): void
    {
        $site = Site::withoutGlobalScope(SiteScope::class)->find($siteId);
        if ($site === null) {
            return;
        }

        DB::table('sites')->where('id', $siteId)->update(['chrome_stale' => $this->isDrifted($site)]);
    }

    /**
     * Recompute every non-onboarding site (the weekly backstop). Returns a tally for the command output.
     *
     * @return array{checked: int, drifted: int, never: int}
     */
    public function sweep(): array
    {
        $checked = $drifted = $never = 0;

        foreach (Site::withoutGlobalScope(SiteScope::class)->where('status', '!=', SiteStatus::Onboarding->value)->get() as $site) {
            $checked++;
            $isDrift = $this->isDrifted($site);
            DB::table('sites')->where('id', $site->id)->update(['chrome_stale' => $isDrift]);

            if ($isDrift) {
                $drifted++;
            } elseif ($this->neverSynced($site)) {
                $never++;
            }
        }

        return ['checked' => $checked, 'drifted' => $drifted, 'never' => $never];
    }

    /**
     * DRIFTED: the site has been synced before, but the freshly-assembled profile no longer matches the
     * fingerprint that was pushed. A never-synced site is NOT drifted (that's {@see neverSynced}).
     */
    public function isDrifted(Site $site): bool
    {
        if ($site->chrome_synced_at === null) {
            return false;
        }

        return SiteProfileAssembler::fingerprint($this->assembler->assemble($site)) !== (string) $site->chrome_synced_hash;
    }

    /**
     * NEVER-SYNCED: a WordPress-connected site whose chrome was never pushed — the live site is running on
     * the theme default, not its own header/footer. Reported separately from drift.
     */
    public function neverSynced(Site $site): bool
    {
        return $site->chrome_synced_at === null && $this->connections->hasVerifiedWordpress((string) $site->id);
    }
}
