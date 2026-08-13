<?php
/**
 * The dedicated service user + role the control plane authenticates as, using a
 * per-site WordPress application password. The role carries a single capability
 * that gates the REST endpoints.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

if (! defined('ABSPATH')) {
    exit;
}

final class ServiceUser
{
    public const ROLE = 'launchpad_service';
    public const CAP = 'lp_manage_content';
    public const LOGIN = 'launchpad-sync';

    public static function install(): void
    {
        add_role(self::ROLE, 'Launchpad Sync', [
            'read' => true,
            self::CAP => true,
        ]);

        // add_role() is a NO-OP when the role already exists — e.g. carried over by a site migration
        // (Duplicator re-imports the DB, so the role's stored caps come along but a plugin re-activation
        // never re-writes them). A stale role can therefore lack the capability, which fails the REST
        // auth with a 403 even though the app password is valid. Patch the existing role explicitly —
        // idempotent, and the actual repair on a moved site.
        $role = get_role(self::ROLE);
        if ($role && ! $role->has_cap(self::CAP)) {
            $role->add_cap(self::CAP);
        }

        // Administrators can also manage, for manual testing.
        $admin = get_role('administrator');
        if ($admin && ! $admin->has_cap(self::CAP)) {
            $admin->add_cap(self::CAP);
        }

        if (! username_exists(self::LOGIN) && function_exists('wp_insert_user')) {
            wp_insert_user([
                'user_login' => self::LOGIN,
                'user_pass' => wp_generate_password(32, true, true),
                'role' => self::ROLE,
                'display_name' => 'Launchpad Sync',
            ]);
        }

        // Belt-and-suspenders for a MIGRATED site: the service user already exists (so it was NOT
        // recreated above with the role), and its role/caps may have drifted in the move. Re-assert the
        // capability directly on the user (usermeta) — independent of the role and immune to role-cache
        // staleness — and make sure it still carries the service role (additive; never removes another).
        $user = get_user_by('login', self::LOGIN);
        if ($user) {
            if (! in_array(self::ROLE, (array) $user->roles, true)) {
                $user->add_role(self::ROLE);
            }
            $user->add_cap(self::CAP);
        }
    }

    /**
     * Cheap, idempotent capability repair — re-grant CAP to the service role and administrators if either
     * lost it. Runs on every request (hooked on `init`), so it heals a MIGRATED/cloned site that carried a
     * stale role over in the DB dump: {@see maybe_upgrade()} only re-runs install() on a version CHANGE, but
     * a migration copies the `lpc_version` option too, so the version matches and the version-gated repair
     * never fires. This is roles-only (in-memory via the loaded roles cache — no query, no user creation),
     * so it's safe to run unconditionally; the heavier user/role repair stays in {@see install()}.
     */
    public static function ensure_caps(): void
    {
        foreach ([self::ROLE, 'administrator'] as $role_name) {
            $role = get_role($role_name);
            if ($role && ! $role->has_cap(self::CAP)) {
                $role->add_cap(self::CAP);
            }
        }
    }

    public static function uninstall(): void
    {
        remove_role(self::ROLE);

        $admin = get_role('administrator');
        if ($admin) {
            $admin->remove_cap(self::CAP);
        }
    }

    /**
     * Authorization callback for the REST endpoints. Authentication itself is
     * handled by WordPress application passwords (Basic auth).
     */
    public static function can_manage(): bool
    {
        return current_user_can(self::CAP);
    }
}
