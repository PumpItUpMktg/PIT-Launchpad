<?php

namespace App\Policies;

use App\Client\ClientAccess;
use App\Enums\UserRole;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;

/**
 * Per-URL index state is client-visible but read-only to clients (§ Client Dashboard v1): a client reads
 * rows for a site their Account owns; operators read any. Writes come only from the URL Inspection sync,
 * so create/update/delete are operator-only.
 */
class PageIndexStatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PageIndexState $state): bool
    {
        return $this->canSeeSite($user, $state->site_id);
    }

    public function create(User $user): bool
    {
        return $this->isOperator($user);
    }

    public function update(User $user, PageIndexState $state): bool
    {
        return $this->isOperator($user);
    }

    public function delete(User $user, PageIndexState $state): bool
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
