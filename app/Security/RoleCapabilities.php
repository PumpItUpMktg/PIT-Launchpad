<?php

namespace App\Security;

use App\Enums\UserRole;
use App\Models\User;

/**
 * The role → capability map: the ONE place that answers "may this role do this?". Kept deliberately
 * declarative so the boundary between the internal Super Admin tier and a client-side Site Admin is
 * auditable at a glance and tunable without touching call sites.
 *
 *  - **Super Admin** (internal team — {@see UserRole::Admin} / {@see UserRole::Operator}) holds EVERY
 *    capability, including the backend-correction and sensitive-admin sets.
 *  - **Site Admin** ({@see UserRole::SiteAdmin}) holds only the OPERATE set — running their own site
 *    end-to-end (view, edit, approve, generate, publish). Never the recover/admin powers, so there is
 *    nothing a Site Admin can do that a Super Admin cannot also undo.
 *  - **Client** ({@see UserRole::Client}) holds no operate/admin capabilities here; the read-only
 *    white-labeled portal governs itself.
 *
 * This class is a pure lookup — it grants nothing on its own. Surfaces opt in by consulting
 * {@see User::hasCapability()} (Stage 2+); until then it changes no existing behavior.
 */
final class RoleCapabilities
{
    /**
     * The Site Admin's day-to-day operating set. Everything NOT listed here is Super-Admin-only.
     * (Tunable: the whole operate-vs-superadmin boundary is these five entries.)
     *
     * @var list<Capability>
     */
    private const array SITE_ADMIN = [
        Capability::ViewDashboards,
        Capability::EditContent,
        Capability::ApproveContent,
        Capability::GenerateContent,
        Capability::PublishContent,
    ];

    /**
     * The capabilities a role holds.
     *
     * @return list<Capability>
     */
    public static function for(UserRole $role): array
    {
        return match ($role) {
            UserRole::Admin, UserRole::Operator => Capability::cases(), // Super Admin: everything
            UserRole::SiteAdmin => self::SITE_ADMIN,
            UserRole::Client => [],
        };
    }

    /** Whether a role holds a specific capability. */
    public static function allows(UserRole $role, Capability $capability): bool
    {
        return in_array($capability, self::for($role), true);
    }
}
