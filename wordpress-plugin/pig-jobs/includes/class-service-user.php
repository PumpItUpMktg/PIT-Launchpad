<?php
/**
 * The dedicated service user + role the Job Capture control plane authenticates as on a STANDALONE site
 * (no companion plugin). Mirrors the companion's identity — same role/cap/login names — so the control
 * plane connects and authorizes identically whether a site runs the companion or this standalone plugin,
 * and an upgrade from standalone → full Launchpad needs no re-connect. The role carries a single capability
 * (`lp_manage_content`) that gates the REST endpoints; administrators get it too, for manual testing.
 *
 * @package PIG\Jobs
 */

namespace PIG\Jobs;

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

        // add_role() is a no-op when the role already exists (e.g. carried over by a site migration), so a
        // stale role can lack the capability — patch it explicitly. Idempotent.
        $role = get_role(self::ROLE);
        if ($role && ! $role->has_cap(self::CAP)) {
            $role->add_cap(self::CAP);
        }

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

        // Belt-and-suspenders for a MIGRATED site: re-assert the cap on the user directly and make sure it
        // still carries the service role (additive; never removes another role).
        $user = get_user_by('login', self::LOGIN);
        if ($user) {
            if (! in_array(self::ROLE, (array) $user->roles, true)) {
                $user->add_role(self::ROLE);
            }
            $user->add_cap(self::CAP);
        }
    }

    /**
     * Cheap, idempotent roles-only capability repair — runs on every request (`init`, so REST too) so a
     * migrated/cloned site that carried a stale role over in its DB dump self-heals without a reactivate,
     * instead of 403-ing an otherwise-valid Application Password.
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
     * Authorization callback for the REST endpoints. Accepts the dedicated service capability OR the core
     * `edit_posts` (back-compat: a standalone site connected as an Editor/Administrator before the service
     * role existed keeps working). Authentication itself is a WordPress Application Password (Basic auth).
     */
    public static function can_manage(): bool
    {
        return current_user_can(self::CAP) || current_user_can('edit_posts');
    }
}
