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

    public function test_an_anonymous_request_never_passes_the_gate_even_when_a_filter_grants_the_capability(): void
    {
        wp_set_current_user(0);
        $grant = static function (array $allcaps): array {
            $allcaps[ServiceUser::CAP] = true; // an install-side filter handing the cap to everyone

            return $allcaps;
        };
        add_filter('user_has_cap', $grant);

        $report = ServiceUser::gate_report();

        $this->assertTrue($report['raw_cap_check'], 'The filter does grant the raw capability to anonymous.');
        $this->assertFalse(ServiceUser::can_manage(), 'The gate must still refuse a request with no user.');
        $this->assertFalse($report['can_manage']);
        $this->assertSame(0, $report['current_user_id']);
        $this->assertContains('closure@10', $report['cap_filters']['user_has_cap']);

        remove_filter('user_has_cap', $grant);
        $this->assertFalse(current_user_can(ServiceUser::CAP));
    }

    public function test_the_service_user_still_passes_the_gate(): void
    {
        ServiceUser::install();
        $user = get_user_by('login', ServiceUser::LOGIN);
        wp_set_current_user($user->ID);

        $this->assertTrue(ServiceUser::can_manage());
        $this->assertTrue(ServiceUser::gate_report()['logged_in']);
    }
}
