<?php
/**
 * Imports a control-plane "bound" Elementor template artifact into this site's
 * Theme Builder, idempotently per kit. This is the H2 import-on-provision push:
 * the engine sends the designer-styled (or generator-fallback) template already
 * carrying its lp/* data-bindings, and the plugin installs it as an
 * `elementor_library` "single" template and points its Display Condition at the
 * `lp_kit` taxonomy term — so the mapped kit renders through the imported design
 * with no manual template authoring on the client site.
 *
 * Split of concerns:
 *   - The IMPORT (create/update the elementor_library post + _elementor_data +
 *     template type + ensure the lp_kit term) is pure WP core — it runs and is
 *     testable on any install, Elementor present or not.
 *   - The DISPLAY CONDITION (Singular → By Term → Launchpad Kit → {kit}) is an
 *     Elementor PRO Theme Builder feature. When Pro is present we persist the
 *     condition; otherwise we record the intended condition as advisory meta and
 *     report condition_set:false so the operator sets it once by hand. Either way
 *     the heavy lifting — the styled, bound template — is installed.
 *
 * Idempotent on the kit: a re-push find-or-updates the same elementor_library post
 * (matched by the KIT_TEMPLATE meta marker), never duplicating templates. It also
 * guards against CONDITION collisions: any other template claiming the same kit's
 * Display Condition (e.g. a hand-made template) has that condition stripped, so the
 * pushed template is the sole owner and a stale design can never win the match.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class KitTemplateStore
{
    /**
     * Install (create or update) the kit's Theme Builder template from a pushed
     * artifact.
     *
     * Payload: {
     *   kit: string,                       // the lp_kit term (e.g. "service-page")
     *   template: { content: array, title?, type?, version?, page_settings? },
     *   template_type?: string,            // Theme Builder location; default "single-page"
     *   title?: string
     * }
     *
     * @param  array<string, mixed>  $payload
     * @return array{kit: string, template_id: int, created: bool, condition_set: bool, pro: bool, condition: array<string, mixed>, conditions_cleared?: array<int, int>, error?: string}
     */
    public function install(array $payload): array
    {
        $kit = trim((string) ($payload['kit'] ?? ''));
        $template = is_array($payload['template'] ?? null) ? $payload['template'] : [];
        $content = is_array($template['content'] ?? null) ? $template['content'] : [];

        $pro = self::pro_active();

        if ($kit === '' || $content === []) {
            return [
                'kit' => $kit,
                'template_id' => 0,
                'created' => false,
                'condition_set' => false,
                'pro' => $pro,
                'condition' => [],
                'error' => 'A non-empty kit and template.content are required.',
            ];
        }

        $template_type = self::template_type($payload);
        $title = (string) ($payload['title'] ?? $template['title'] ?? ('Launchpad Kit — ' . $kit));

        $existing_id = $this->find($kit);

        $postarr = [
            'post_type' => 'elementor_library',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => '',
        ];
        if ($existing_id > 0) {
            $postarr['ID'] = $existing_id;
        }

        // Reuse the content store's edit guard so the resulting save_post is not
        // mistaken for a human edit. $wp_error=true → a failure is a WP_Error to
        // inspect, never (int)-cast (that fatals in PHP 8).
        $result = EditGuard::during_write(static function () use ($postarr, $existing_id) {
            return $existing_id > 0
                ? wp_update_post(wp_slash($postarr), true)
                : wp_insert_post(wp_slash($postarr), true);
        });

        if (is_wp_error($result)) {
            return [
                'kit' => $kit,
                'template_id' => $existing_id,
                'created' => false,
                'condition_set' => false,
                'pro' => $pro,
                'condition' => [],
                'error' => $result->get_error_code() . ': ' . $result->get_error_message(),
            ];
        }

        $post_id = (int) $result;
        if ($post_id <= 0) {
            return [
                'kit' => $kit,
                'template_id' => $existing_id,
                'created' => false,
                'condition_set' => false,
                'pro' => $pro,
                'condition' => [],
                'error' => 'WordPress returned no post id for the template.',
            ];
        }

        $this->store_elementor_data($post_id, $content, $template_type, (string) ($template['version'] ?? ''));
        $this->record_marker($post_id, $kit);

        // Ensure the lp_kit term the condition targets exists, then set (or advise)
        // the Display Condition.
        $term_id = $this->ensure_kit_term($kit);
        $condition = $this->apply_condition($post_id, $kit, $term_id, $pro);

        // Collision guard: make THIS template the sole owner of the kit's condition.
        // Any OTHER template claiming the same lp_kit condition (a hand-made or
        // legacy template) has that condition stripped — Elementor does not
        // guarantee which of two identical conditions wins, so we don't rely on
        // supersession; we remove the conflict deterministically.
        $cleared = $this->clear_conflicting_conditions($kit, $term_id, $post_id);

        $this->flush_elementor_cache($post_id);
        if ($cleared !== []) {
            $this->flush_condition_cache();
        }

        return [
            'kit' => $kit,
            'template_id' => $post_id,
            'created' => $existing_id === 0,
            'condition_set' => (bool) ($condition['set'] ?? false),
            'pro' => $pro,
            'condition' => $condition,
            'conditions_cleared' => $cleared,
        ];
    }

    /**
     * Strip this kit's Display Condition from every elementor_library template
     * EXCEPT the canonical one ($keep_post_id), so two templates can never both
     * claim the kit and render a stale design. Matches the kit's term in the rule
     * (`…/in_lp_kit/<term_id>`); other conditions on those templates are preserved,
     * and our advisory marker for this kit is cleared too. Returns the ids touched.
     *
     * Operates purely on `_elementor_conditions` postmeta (Pro's own storage), so it
     * runs whether or not Pro is active — a conflict can predate the current state.
     *
     * @return array<int, int>
     */
    private function clear_conflicting_conditions(string $kit, int $term_id, int $keep_post_id): array
    {
        if ($term_id <= 0) {
            return [];
        }

        $needle = sprintf('in_%s/%d', KitTaxonomy::TAXONOMY, $term_id);

        $candidates = get_posts([
            'post_type' => 'elementor_library',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => '_elementor_conditions',
            'suppress_filters' => false,
        ]);

        $cleared = [];
        foreach ($candidates as $candidate) {
            $pid = (int) $candidate;
            if ($pid === $keep_post_id) {
                continue;
            }

            $conditions = get_post_meta($pid, '_elementor_conditions', true);
            if (! is_array($conditions)) {
                continue;
            }

            $kept = array_values(array_filter(
                $conditions,
                static fn ($rule): bool => ! is_string($rule) || ! str_contains($rule, $needle)
            ));

            if (count($kept) === count($conditions)) {
                continue; // no rule for this kit on this template
            }

            if ($kept === []) {
                delete_post_meta($pid, '_elementor_conditions');
            } else {
                update_post_meta($pid, '_elementor_conditions', $kept);
            }

            // Drop our advisory marker too when it pointed at this kit.
            $advisory = get_post_meta($pid, Meta::KIT_TEMPLATE_CONDITION, true);
            if (is_array($advisory) && (string) ($advisory['term_id'] ?? '') === (string) $term_id) {
                delete_post_meta($pid, Meta::KIT_TEMPLATE_CONDITION);
            }

            $cleared[] = $pid;
        }

        return $cleared;
    }

    /**
     * Best-effort flush of Elementor Pro's Theme Builder conditions cache so a
     * reassignment takes effect without a manual rebuild. The option is Pro's own
     * cache store; deleting it forces a rebuild and is a safe no-op when absent.
     */
    private function flush_condition_cache(): void
    {
        delete_option('elementor_pro_theme_builder_conditions');
    }

    /**
     * The Elementor document payload: the data tree, the Theme Builder location
     * type (meta + the elementor_library_type taxonomy, mirrored as Elementor
     * itself does), editor mode, and version. `_elementor_data` is the slashed
     * JSON of the elements tree.
     *
     * @param  list<array<string, mixed>>  $content
     */
    private function store_elementor_data(int $post_id, array $content, string $template_type, string $version): void
    {
        // wp_slash so wp_update_post-style meta storage round-trips the JSON; this
        // is the exact shape Elementor reads back into the editor.
        update_post_meta($post_id, '_elementor_data', wp_slash((string) wp_json_encode($content)));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', $template_type);

        if ($version !== '') {
            update_post_meta($post_id, '_elementor_version', $version);
        }

        // Elementor mirrors the template type into this taxonomy; set it so the
        // /templates inventory (and Elementor's own library UI) report it correctly.
        wp_set_object_terms($post_id, $template_type, 'elementor_library_type', false);
    }

    /**
     * Stamp the per-kit idempotency marker so a re-push updates this same template.
     */
    private function record_marker(int $post_id, string $kit): void
    {
        update_post_meta($post_id, Meta::KIT_TEMPLATE, $kit);
    }

    /**
     * Ensure the `lp_kit` term for this kit exists (it is the condition target),
     * returning its term_id. The KitTaxonomy must be registered (it is, on boot).
     */
    private function ensure_kit_term(string $kit): int
    {
        $term = get_term_by('slug', sanitize_title($kit), KitTaxonomy::TAXONOMY);
        if ($term instanceof \WP_Term) {
            return (int) $term->term_id;
        }

        $result = wp_insert_term($kit, KitTaxonomy::TAXONOMY);
        if (is_wp_error($result)) {
            $existing = get_term_by('name', $kit, KitTaxonomy::TAXONOMY);

            return $existing instanceof \WP_Term ? (int) $existing->term_id : 0;
        }

        return (int) $result['term_id'];
    }

    /**
     * Set the Theme Builder Display Condition (Singular → By Term → Launchpad Kit
     * → {kit}) when Elementor Pro is present; otherwise record it as advisory meta
     * for the operator. Returns a structured descriptor either way.
     *
     * The condition is also persisted to `_elementor_conditions` (Pro's own
     * storage) so a manual confirm in the UI shows it pre-filled; on free it is
     * inert but harmless.
     *
     * @return array{set: bool, taxonomy: string, term: string, term_id: int, location: string, rule: string}
     */
    private function apply_condition(int $post_id, string $kit, int $term_id, bool $pro): array
    {
        // Elementor Pro condition rule string: include / singular / in_<taxonomy> / <term_id>.
        $rule = sprintf('include/singular/in_%s/%d', KitTaxonomy::TAXONOMY, $term_id);

        $descriptor = [
            'set' => false,
            'taxonomy' => KitTaxonomy::TAXONOMY,
            'term' => $kit,
            'term_id' => $term_id,
            'location' => 'singular',
            'rule' => $rule,
        ];

        // Always record the intended condition as advisory meta — the operator's
        // reference whether or not Pro applied it automatically.
        update_post_meta($post_id, Meta::KIT_TEMPLATE_CONDITION, $descriptor);

        if (! $pro || $term_id <= 0) {
            return $descriptor;
        }

        // Persist Pro's own conditions store. Wrapped defensively: the exact API
        // surface varies across Pro versions, so the postmeta is the stable path
        // and the manager call is best-effort on top of it.
        update_post_meta($post_id, '_elementor_conditions', [$rule]);

        $descriptor['set'] = true;

        return $descriptor;
    }

    /**
     * Clear Elementor's generated CSS cache for the template so the freshly
     * imported data renders without a manual regenerate. No-op without Elementor.
     */
    private function flush_elementor_cache(int $post_id): void
    {
        if (! class_exists('\Elementor\Plugin')) {
            return;
        }

        $elementor = \Elementor\Plugin::$instance;
        if (isset($elementor->files_manager) && is_object($elementor->files_manager)
            && method_exists($elementor->files_manager, 'clear_cache')) {
            $elementor->files_manager->clear_cache();
        }
    }

    /**
     * The Theme Builder location type for the imported template. Defaults to
     * `single-page` (a kit page is a WP page); an explicit payload override wins.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function template_type(array $payload): string
    {
        $type = trim((string) ($payload['template_type'] ?? ''));

        return $type !== '' ? $type : 'single-page';
    }

    private static function pro_active(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION') || class_exists('\ElementorPro\Plugin');
    }

    /**
     * Find an existing imported template for this kit by its idempotency marker.
     */
    private function find(string $kit): int
    {
        $posts = get_posts([
            'post_type' => 'elementor_library',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => Meta::KIT_TEMPLATE,
            'meta_value' => $kit,
            'suppress_filters' => false,
        ]);

        return $posts ? (int) $posts[0] : 0;
    }
}
