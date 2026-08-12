<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\JobCpt;
use Launchpad\Companion\Content\JobStore;
use Launchpad\Companion\Meta;

class Test_Job_Store extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        JobCpt::register();
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'job_id' => 'JOB0000000000000000000001',
            'status' => 'publish',
            'title' => 'Sump Pump Replacement',
            'slug' => 'sump-pump-replacement-000001',
            'description' => "First paragraph.\n\nSecond paragraph.",
            'client_name' => 'Jane H.',
            'seo' => ['title' => 'Sump Pump Replacement', 'meta_description' => 'We replaced a sump pump.'],
            'location' => ['city' => 'Bedminster', 'county' => 'Somerset County', 'state' => 'NJ', 'lat' => 40.71, 'lng' => -74.65],
            'job_types' => [['label' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair']],
            'images' => [], // no sideload in the harness
        ], $over);
    }

    public function test_upsert_creates_a_pig_job_keyed_on_ulid(): void
    {
        $result = ( new JobStore() )->upsert($this->payload());

        $this->assertSame('publish', $result['status']);
        $this->assertGreaterThan(0, $result['wp_post_id']);

        $post = get_post($result['wp_post_id']);
        $this->assertSame(JobCpt::POST_TYPE, $post->post_type);
        $this->assertSame('JOB0000000000000000000001', get_post_meta($post->ID, Meta::JOB_ID, true));
        $this->assertStringContainsString('First paragraph', $post->post_content);
        $this->assertSame('We replaced a sump pump.', $post->post_excerpt);
        $this->assertContains('Bedminster', wp_get_object_terms($post->ID, JobCpt::TAX_CITY, ['fields' => 'names']));
        $this->assertContains('Sump Pump Repair', wp_get_object_terms($post->ID, JobCpt::TAX_SERVICE, ['fields' => 'names']));
    }

    public function test_upsert_is_idempotent_by_ulid(): void
    {
        $store = new JobStore();
        $first = $store->upsert($this->payload());
        $second = $store->upsert($this->payload(['title' => 'Updated Title']));

        $this->assertSame($first['wp_post_id'], $second['wp_post_id']);

        $found = get_posts([
            'post_type' => JobCpt::POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'meta_key' => Meta::JOB_ID,
            'meta_value' => 'JOB0000000000000000000001',
            'numberposts' => -1,
        ]);
        $this->assertCount(1, $found);
    }

    public function test_delete_removes_the_post_and_is_idempotent(): void
    {
        $store = new JobStore();
        $created = $store->upsert($this->payload());

        $this->assertTrue($store->delete('JOB0000000000000000000001')['deleted']);
        $this->assertNull(get_post($created['wp_post_id']));

        // Deleting again is still a success (idempotent).
        $this->assertTrue($store->delete('JOB0000000000000000000001')['deleted']);
    }

    public function test_missing_job_id_is_an_error(): void
    {
        $result = ( new JobStore() )->upsert($this->payload(['job_id' => '']));

        $this->assertSame('error', $result['status']);
    }
}
