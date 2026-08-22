<?php

namespace App\Policies;

use App\Client\ClientAccess;
use App\Enums\UserRole;
use App\Models\ClientMilestone;
use App\Models\Site;
use App\Models\User;

/**
 * Milestones are the client's narrative beats (§ Client Dashboard v1): a client reads the client-visible
 * milestones for a site their Account owns; operators read any. They are derived, never hand-entered, so
 * create/update/delete are operator-only.
 */
class ClientMilestonePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClientMilestone $milestone): bool
    {
        if (! $this->canSeeSite($user, $milestone->site_id)) {
            return false;
        }

        // A client only ever sees client-visible milestones; operators see all (internal ones included).
        return $milestone->is_client_visible || $this->isOperator($user);
    }

    public function create(User $user): bool
    {
        return $this->isOperator($user);
    }

    public function update(User $user, ClientMilestone $milestone): bool
    {
        return $this->isOperator($user);
    }

    public function delete(User $user, ClientMilestone $milestone): bool
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
