<?php
/**
 * Inventory of this site's Elementor saved templates for the launchpad/v1/templates
 * endpoint: enumerates the `elementor_library` CPT so the control plane can offer
 * an operator a live, eyes-on mapping of each kit to a real template on the site.
 * Read-only; no secrets. Per template: id, title, slug, the Elementor template
 * type (page / single-post / section / …), last-modified, and a preview link +
 * thumbnail where Elementor provides one.
 *
 * Elementor is referenced defensively — the CPT enumeration is pure WP core, and
 * the preview/thumbnail helpers degrade to null when Elementor's document API is
 * absent (e.g. Atomic-only), so the endpoint never fatals on a thin install.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Rest;

if (! defined('ABSPATH')) {
    exit;
}

final class Templates
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        // post_status => 'any', not 'publish': Theme Builder templates (single-page,
        // single-post, header, footer) are often saved unpublished, and filtering to
        // 'publish' dropped them entirely — leaving only the page/container library
        // templates, which is why every returned type read as "container". 'any'
        // returns the whole library across all Theme Builder groups.
        $posts = get_posts([
            'post_type' => 'elementor_library',
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        $templates = [];
        foreach ($posts as $post) {
            $templates[] = [
                'id' => (int) $post->ID,
                'title' => get_the_title($post) !== '' ? get_the_title($post) : '(untitled)',
                'slug' => (string) $post->post_name,
                'type' => self::template_type((int) $post->ID),
                'modified' => (string) get_post_modified_time('c', true, $post),
                'preview_url' => self::preview_url($post),
                'thumbnail' => self::thumbnail((int) $post->ID),
            ];
        }

        return ['templates' => $templates];
    }

    /**
     * The Elementor template type — single-page / single-post / header / footer /
     * page / container / … — read from `_elementor_template_type` meta, with a
     * fallback to the `elementor_library_type` taxonomy term in case the meta key
     * shifts under Elementor v4 (theme_builder_v2 + atomic). One of them holds the
     * real type; never default it to "container".
     */
    private static function template_type(int $post_id): string
    {
        $type = get_post_meta($post_id, '_elementor_template_type', true);
        if (is_string($type) && $type !== '') {
            return $type;
        }

        $terms = get_the_terms($post_id, 'elementor_library_type');
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof \WP_Term && $term->slug !== '') {
                    return $term->slug;
                }
            }
        }

        return '';
    }

    /**
     * Elementor's own preview URL for the document when its API is present; else a
     * best-effort permalink with the Elementor preview flag.
     */
    private static function preview_url(\WP_Post $post): ?string
    {
        if (class_exists('\Elementor\Plugin')) {
            $elementor = \Elementor\Plugin::$instance;
            if (isset($elementor->documents) && is_object($elementor->documents)) {
                $document = $elementor->documents->get($post->ID);
                if (is_object($document) && method_exists($document, 'get_preview_url')) {
                    $url = (string) $document->get_preview_url();
                    if ($url !== '') {
                        return $url;
                    }
                }
            }
        }

        $permalink = get_permalink($post);

        return $permalink !== false ? add_query_arg('elementor-preview', (string) $post->ID, $permalink) : null;
    }

    /**
     * The template's screenshot/thumbnail: Elementor's stored screenshot attachment
     * when present, else the featured image, else null.
     */
    private static function thumbnail(int $post_id): ?string
    {
        $screenshot = get_post_meta($post_id, '_elementor_screenshot', true);
        if (is_string($screenshot) && $screenshot !== '') {
            return $screenshot;
        }

        $featured = get_the_post_thumbnail_url($post_id, 'medium');

        return is_string($featured) && $featured !== '' ? $featured : null;
    }
}
