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
