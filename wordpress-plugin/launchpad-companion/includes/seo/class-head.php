<?php
/**
 * Native head SEO for managed pages (no SEO plugin): title, meta description,
 * canonical, robots, and OpenGraph + Twitter Card tags (image from R2 URL).
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Seo;

use Launchpad\Companion\Render\Payload;

if (! defined('ABSPATH')) {
    exit;
}

final class Head
{
    public function register(): void
    {
        add_filter('pre_get_document_title', [$this, 'title']);
        add_filter('wp_robots', [$this, 'robots']);
        add_filter('get_canonical_url', [$this, 'canonical'], 10, 2);
        add_action('wp_head', [$this, 'emit'], 1);
    }

    private function managed_id(): int
    {
        if (! is_singular()) {
            return 0;
        }

        $id = (int) get_queried_object_id();

        return Payload::is_managed($id) ? $id : 0;
    }

    public function title(string $title): string
    {
        $id = $this->managed_id();

        if ($id === 0) {
            return $title;
        }

        $seo = Payload::seo($id);

        return ! empty($seo['title']) ? (string) $seo['title'] : $title;
    }

    /**
     * @param  array<string, mixed>  $robots
     * @return array<string, mixed>
     */
    public function robots(array $robots): array
    {
        $id = $this->managed_id();

        if ($id === 0) {
            return $robots;
        }

        $directive = (string) (Payload::seo($id)['robots'] ?? 'index, follow');

        if (str_contains($directive, 'noindex')) {
            unset($robots['index']);
            $robots['noindex'] = true;
        }

        if (str_contains($directive, 'nofollow')) {
            unset($robots['follow']);
            $robots['nofollow'] = true;
        }

        return $robots;
    }

    public function canonical(string $canonical, mixed $post): string
    {
        $id = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;

        if ($id === 0 || ! Payload::is_managed($id)) {
            return $canonical;
        }

        $url = (string) (Payload::seo($id)['canonical'] ?? '');

        return $url !== '' ? $url : $canonical;
    }

    public function emit(): void
    {
        $id = $this->managed_id();

        if ($id === 0) {
            return;
        }

        $seo = Payload::seo($id);
        $title = (string) ($seo['title'] ?? get_the_title($id));
        $description = (string) ($seo['meta_description'] ?? '');
        $url = (string) ($seo['canonical'] ?? get_permalink($id));
        $image = $this->primary_image($id, $seo);

        if ($description !== '') {
            printf('<meta name="description" content="%s" />' . "\n", esc_attr($description));
        }

        $tags = [
            'og:type' => 'website',
            'og:title' => $title,
            'og:description' => $description,
            'og:url' => $url,
            'og:image' => $image,
            'twitter:card' => $image !== '' ? 'summary_large_image' : 'summary',
            'twitter:title' => $title,
            'twitter:description' => $description,
            'twitter:image' => $image,
        ];

        foreach ($tags as $property => $content) {
            if ($content === '') {
                continue;
            }

            $attr = str_starts_with($property, 'twitter:') ? 'name' : 'property';
            printf('<meta %s="%s" content="%s" />' . "\n", $attr, esc_attr($property), esc_attr((string) $content));
        }
    }

    /**
     * @param  array<string, mixed>  $seo
     */
    private function primary_image(int $id, array $seo): string
    {
        if (isset($seo['og']['image']) && $seo['og']['image'] !== '') {
            return (string) $seo['og']['image'];
        }

        $images = get_post_meta($id, \Launchpad\Companion\Meta::IMAGES, true);

        if (is_array($images)) {
            foreach ($images as $image) {
                if (is_array($image) && ! empty($image['url'])) {
                    return (string) $image['url'];
                }
            }
        }

        return '';
    }
}
