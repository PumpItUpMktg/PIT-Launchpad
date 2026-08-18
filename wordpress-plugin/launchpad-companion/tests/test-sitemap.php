<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\JobCpt;
use Launchpad\Companion\Meta;
use Launchpad\Companion\Sitemap;

class Test_Sitemap extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        JobCpt::register();
    }

    /** Invoke one of the Sitemap's private render methods and return its XML. */
    private function render(string $method): string
    {
        $r = new ReflectionMethod(Sitemap::class, $method);
        $r->setAccessible(true);

        return (string) $r->invoke(new Sitemap());
    }

    /**
     * Create a pig_job post, defaulting to a quality one (published, body, city, featured image). Pass
     * overrides to strip a signal: 'status', 'content', 'city' (null = none), 'thumb' (false = none).
     *
     * @param  array<string, mixed>  $over
     */
    private function make_job(array $over = []): int
    {
        static $seq = 0;
        $seq++;

        $id = (int) self::factory()->post->create([
            'post_type' => JobCpt::POST_TYPE,
            'post_status' => $over['status'] ?? 'publish',
            'post_title' => 'Completed Job ' . $seq,
            'post_content' => array_key_exists('content', $over) ? (string) $over['content'] : '<p>A real, specific write-up of the completed job.</p>',
        ]);

        update_post_meta($id, Meta::JOB_ID, 'JOB' . str_pad((string) $seq, 23, '0', STR_PAD_LEFT));

        $city = array_key_exists('city', $over) ? $over['city'] : 'Bedminster';
        if ($city !== null) {
            JobCpt::assign($id, JobCpt::TAX_CITY, [['name' => (string) $city]]);
        }

        if (($over['thumb'] ?? true)) {
            $att = (int) self::factory()->attachment->create_object('photo-' . $seq . '.jpg', $id, ['post_mime_type' => 'image/jpeg']);
            set_post_thumbnail($id, $att);
        }

        return $id;
    }

    public function test_jobs_sitemap_lists_a_quality_published_job(): void
    {
        $id = $this->make_job();

        $xml = $this->render('render_jobs');

        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringContainsString((string) get_permalink($id), $xml);
    }

    public function test_jobs_sitemap_excludes_thin_and_unpublished_jobs(): void
    {
        $draft = $this->make_job(['status' => 'draft']);
        $noPhoto = $this->make_job(['thumb' => false]);
        $noBody = $this->make_job(['content' => '']);
        $noCity = $this->make_job(['city' => null]);
        $good = $this->make_job();

        $xml = $this->render('render_jobs');

        $this->assertStringContainsString((string) get_permalink($good), $xml);
        foreach ([$draft, $noPhoto, $noBody, $noCity] as $excluded) {
            $this->assertStringNotContainsString((string) get_permalink($excluded), $xml);
        }
    }

    public function test_index_references_the_jobs_sitemap_only_when_a_quality_job_exists(): void
    {
        // No jobs → the index lists content only.
        $this->assertStringNotContainsString('sitemap-jobs.xml', $this->render('render_index'));
        $this->assertStringContainsString('sitemap-content.xml', $this->render('render_index'));

        // A quality job → the index now advertises the jobs child.
        $this->make_job();
        $this->assertStringContainsString('sitemap-jobs.xml', $this->render('render_index'));
    }

    public function test_index_ignores_a_thin_only_job_set(): void
    {
        $this->make_job(['thumb' => false]); // only a thin job exists → still no jobs sitemap advertised

        $this->assertStringNotContainsString('sitemap-jobs.xml', $this->render('render_index'));
    }
}
