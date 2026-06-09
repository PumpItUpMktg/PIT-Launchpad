<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\EditGuard;
use Launchpad\Companion\Meta;

class Test_Edit_Guard extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        ( new EditGuard() )->register();
    }

    private function managed_post(): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        update_post_meta($id, Meta::CONTENT_ID, '01JMANAGED000000000000000');

        return (int) $id;
    }

    public function test_a_human_edit_flags_the_post_locally_edited(): void
    {
        $id = $this->managed_post();

        // A human save in wp-admin (outside during_write).
        wp_update_post(['ID' => $id, 'post_title' => 'Edited by a human']);

        $this->assertTrue(EditGuard::is_locally_edited($id));
    }

    public function test_a_plugin_write_does_not_flag_locally_edited(): void
    {
        $id = $this->managed_post();

        EditGuard::during_write(static function () use ($id) {
            wp_update_post(['ID' => $id, 'post_title' => 'Engine push']);
        });

        $this->assertFalse(EditGuard::is_locally_edited($id), 'The plugin\'s own write must not self-flag.');
    }

    public function test_record_push_clears_the_edited_flag(): void
    {
        $id = $this->managed_post();
        update_post_meta($id, Meta::LOCALLY_EDITED, '1');

        EditGuard::record_push($id, 'hash123');

        $this->assertFalse(EditGuard::is_locally_edited($id));
        $this->assertSame('hash123', get_post_meta($id, Meta::LAST_PUSH, true));
    }

    public function test_an_unmanaged_post_is_never_flagged(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page']);
        wp_update_post(['ID' => $id, 'post_title' => 'Just a normal page']);

        $this->assertFalse(EditGuard::is_locally_edited((int) $id));
    }
}
