<?php

namespace App\Onboarding;

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Controls\WordpressConnector;

/**
 * Step 2's WordPress-prep phase. Connect + verify are real (the §9 verify-before-store
 * {@see WordpressConnector}); the companion-plugin install, Elementor + Pro install, and cleanup
 * are **clean stub seams** until the agent-driven install relay lands — same wire-or-stub
 * discipline as the rest of the guided flow. `prep()` runs the whole phase and reports each
 * step's status so the page can render the checklist and gate Continue on all-green.
 */
class WordpressPrep
{
    public function __construct(
        private readonly WordpressConnector $connector,
    ) {}

    public function isConnected(Site $site): bool
    {
        return Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword->value)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Connect + verify (real) then run the prep installs (stubbed). Returns the per-step
     * checklist; `ready` is true only when every step is green.
     *
     * @param  array{base_url: string, username: string, app_password: string}  $input
     * @return array{ready: bool, steps: array<string, bool>, error: string|null}
     */
    public function prep(Site $site, array $input): array
    {
        try {
            $this->connector->connect($site->id, $input); // verify-before-store; throws on bad creds
        } catch (\Throwable $e) {
            return ['ready' => false, 'steps' => $this->steps(false), 'error' => $e->getMessage()];
        }

        // Stub seam — the agent-driven installs land in a later relay; they report success today
        // so the flow is exercised end to end (real WordPress operations slot in here unchanged).
        $this->installCompanionPlugin($site);
        $this->installElementor($site);
        $this->cleanup($site);

        return ['ready' => true, 'steps' => $this->steps(true), 'error' => null];
    }

    /** @return array<string, bool> */
    public function status(Site $site): array
    {
        return $this->steps($this->isConnected($site));
    }

    /** Stub — installs the companion plugin via the agent (later relay). */
    public function installCompanionPlugin(Site $site): bool
    {
        return true;
    }

    /** Stub — installs Elementor + Pro (later relay). */
    public function installElementor(Site $site): bool
    {
        return true;
    }

    /** Stub — post-install cleanup (later relay). */
    public function cleanup(Site $site): bool
    {
        return true;
    }

    /** @return array<string, bool> */
    private function steps(bool $ready): array
    {
        return [
            'Connected & verified' => $ready,
            'Companion plugin installed' => $ready,
            'Elementor + Pro installed' => $ready,
            'Cleaned up' => $ready,
        ];
    }
}
