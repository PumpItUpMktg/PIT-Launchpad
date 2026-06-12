<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\ContentStore;

class Test_Featured_Image extends WP_UnitTestCase
{
    private int $attachment_id = 0;

    public function set_up(): void
    {
        parent::set_up();

        // Stub the R2 sideload: return a real local attachment so set_post_thumbnail
        // has a valid id, no network.
        $this->attachment_id = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );

        add_filter('lp_pre_import_image', function ($pre, array $image) {
            return array_merge($image, [
                'attachment_id' => $this->attachment_id,
                'source_url' => $image['url'],
                'url' => wp_get_attachment_url($this->attachment_id),
            ]);
        }, 10, 2);
    }

    public function test_a_post_gets_its_featured_image_set_from_the_hero(): void
    {
        $result = ( new ContentStore() )->upsert([
            'content_id' => '01JFEATURED0000000000000A',
            'kind' => 'post',
            'kit' => '',
            'slug' => 'a-news-post',
            'status' => 'published',
            'slot_payload' => ['body' => '<p>Article.</p>'],
            'images' => ['hero' => ['url' => 'https://r2.example/sites/s1/a-news-post-hero.webp', 'alt' => 'Hero']],
            'featured_image' => 'https://r2.example/sites/s1/a-news-post-hero.webp',
        ]);

        $this->assertSame($this->attachment_id, get_post_thumbnail_id($result['wp_post_id']));
    }

    public function test_no_featured_image_field_leaves_the_thumbnail_unset(): void
    {
        $result = ( new ContentStore() )->upsert([
            'content_id' => '01JFEATURED0000000000000B',
            'kind' => 'post',
            'slug' => 'no-image-post',
            'status' => 'published',
            'slot_payload' => ['body' => '<p>Article.</p>'],
        ]);

        $this->assertSame(0, get_post_thumbnail_id($result['wp_post_id']));
    }
}
