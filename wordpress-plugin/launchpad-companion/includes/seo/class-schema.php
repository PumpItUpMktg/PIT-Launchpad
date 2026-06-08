<?php
/**
 * JSON-LD emission per the page-type SEO profile: the page-type schema (from
 * schema_type + schema_payload), BreadcrumbList, FAQPage (from the faq slot),
 * and ImageObject (from the payload images) — combined in one @graph.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Seo;

use Launchpad\Companion\Meta;
use Launchpad\Companion\Render\Payload;

if (! defined('ABSPATH')) {
    exit;
}

final class Schema
{
    public function register(): void
    {
        add_action('wp_head', [$this, 'emit'], 5);
    }

    public function emit(): void
    {
        if (! is_singular()) {
            return;
        }

        $id = (int) get_queried_object_id();

        if (! Payload::is_managed($id)) {
            return;
        }

        $graph = array_filter([
            $this->page_node($id),
            $this->breadcrumb_node($id),
            $this->faq_node($id),
            $this->image_node($id),
        ]);

        if ($graph === []) {
            return;
        }

        $json = wp_json_encode(
            ['@context' => 'https://schema.org', '@graph' => array_values($graph)],
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * @return array<string, mixed>|null
     */
    private function page_node(int $id): ?array
    {
        $seo = Payload::seo($id);
        $type = (string) ($seo['schema_type'] ?? '');
        $payload = is_array($seo['schema_payload'] ?? null) ? $seo['schema_payload'] : [];

        if ($type === '' && $payload === []) {
            return null;
        }

        $node = $payload;
        if (! isset($node['@type']) && $type !== '') {
            $node['@type'] = $type;
        }
        $node['name'] ??= get_the_title($id);
        $node['url'] ??= get_permalink($id);

        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function breadcrumb_node(int $id): ?array
    {
        $trail = Breadcrumbs::trail($id);

        if ($trail === []) {
            return null;
        }

        $items = [];
        foreach ($trail as $position => $crumb) {
            $items[] = array_filter([
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'] !== '' ? $crumb['url'] : null,
            ], static fn ($v) => $v !== null);
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function faq_node(int $id): ?array
    {
        $faq = Payload::slot($id, 'faq');

        if (! is_array($faq) || $faq === []) {
            return null;
        }

        $entities = [];
        foreach ($faq as $item) {
            if (is_array($item) && isset($item['question'], $item['answer'])) {
                $entities[] = [
                    '@type' => 'Question',
                    'name' => (string) $item['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) $item['answer']],
                ];
            }
        }

        return $entities === [] ? null : ['@type' => 'FAQPage', 'mainEntity' => $entities];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function image_node(int $id): ?array
    {
        $images = get_post_meta($id, Meta::IMAGES, true);

        if (! is_array($images)) {
            return null;
        }

        foreach ($images as $image) {
            if (is_array($image) && ! empty($image['url'])) {
                return array_filter([
                    '@type' => 'ImageObject',
                    'url' => (string) $image['url'],
                    'caption' => isset($image['caption']) ? (string) $image['caption'] : null,
                    'width' => isset($image['width']) ? (int) $image['width'] : null,
                    'height' => isset($image['height']) ? (int) $image['height'] : null,
                ], static fn ($v) => $v !== null);
            }
        }

        return null;
    }
}
