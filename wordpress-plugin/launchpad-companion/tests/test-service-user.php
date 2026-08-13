<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\ServiceUser;

class Test_Service_User extends WP_UnitTestCase
{
    public function test_can_manage_requires_the_service_capability(): void
    {
        $subscriber = self::factory()->user->create_and_get(['role' => 'subscriber']);
        wp_set_current_user($subscriber->ID);
        $this->assertFalse(ServiceUser::can_manage(), 'A subscriber must not pass the REST auth gate.');

        $admin = self::factory()->user->create_and_get(['role' => 'administrator']);
        wp_set_current_user($admin->ID);
        $this->assertTrue(ServiceUser::can_manage(), 'An administrator carries the capability.');
    }

    public function test_ensure_caps_re_grants_a_role_that_lost_the_capability(): void
    {
        // Simulate a migrated/cloned site: the service role exists but WITHOUT the capability (the version
        // option would have come across too, so the version-gated repair never fires).
        $role = get_role(ServiceUser::ROLE);
        if ($role) {
            $role->remove_cap(ServiceUser::CAP);
        } else {
            add_role(ServiceUser::ROLE, 'Launchpad Sync', ['read' => true]);
        }
        $this->assertFalse((bool) get_role(ServiceUser::ROLE)->has_cap(ServiceUser::CAP));

        ServiceUser::ensure_caps();

        $this->assertTrue(
            (bool) get_role(ServiceUser::ROLE)->has_cap(ServiceUser::CAP),
            'ensure_caps() must self-heal a service role that lost the capability.'
        );
    }

    public function test_ensure_caps_re_grants_administrators(): void
    {
        get_role('administrator')->remove_cap(ServiceUser::CAP);
        $this->assertFalse((bool) get_role('administrator')->has_cap(ServiceUser::CAP));

        ServiceUser::ensure_caps();

        $this->assertTrue((bool) get_role('administrator')->has_cap(ServiceUser::CAP));
    }
}
