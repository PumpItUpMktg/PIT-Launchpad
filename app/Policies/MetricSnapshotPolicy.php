<?php

namespace App\Policies;

use App\Client\ClientAccess;
use App\Enums\UserRole;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Models\User;

/**
 * The metric spine is client-visible but read-only to clients (§ Client Dashboard v1): a client may read
 * rows for a site their Account owns; operators may read any. Nobody writes through the policy — snapshots
 * are only ever produced by providers/backfill, so create/update/delete are operator-only.
 */
class MetricSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // row-level scoping (global SiteScope + view()) does the filtering
    }

    public function view(User $user, MetricSnapshot $snapshot): bool
    {
        return $this->canSeeSite($user, $snapshot->site_id);
    }

    public function create(User $user): bool
    {
        return $this->isOperator($user);
    }

    public function update(User $user, MetricSnapshot $snapshot): bool
    {
        return $this->isOperator($user);
    }

    public function delete(User $user, MetricSnapshot $snapshot): bool
    {
        return $this->isOperator($user);
    }

    private function canSeeSite(User $user, string $siteId): bool
    {
        if ($this->isOperator($user)) {
            return true;
        }

        $site = Site::withoutGlobalScopes()->find($siteId);

        return $site !== null && app(ClientAccess::class)->canSee($user, $site);
    }

    private function isOperator(User $user): bool
    {
        return $user->role === UserRole::Operator;
    }
}
