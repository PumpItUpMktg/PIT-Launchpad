<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

/**
 * Gating layer 3 — record-level authorization on sites. The visibility scope already hides a
 * non-permitted site from every query; this backs it with an explicit gate so a direct `authorize` /
 * Filament record action can never act on a tenant the operator isn't a member of. Admin (and
 * unrestricted operators) pass everything.
 */
class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isStaff();
    }

    public function view(User $user, Site $site): bool
    {
        return $user->canSeeSite($site);
    }

    public function update(User $user, Site $site): bool
    {
        return $user->canSeeSite($site);
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->isAdmin() && $user->canSeeSite($site);
    }
}
