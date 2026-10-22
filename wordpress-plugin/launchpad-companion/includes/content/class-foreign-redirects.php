<?php
/**
 * Reads the redirect rules OTHER plugins on this site hold — straight from their database storage, so a
 * broken plugin UI is never in the way of migrating them to the control plane. Known layouts are
 * normalized (301 Redirects / 301 Redirects Pro, Redirection, Safe Redirect Manager); anything else
 * with "redirect" in a table or option name is dumped by shape so the engine can report it. Read-only;
 * never touches the plugin's data; the companion's own map (lp_redirects) is excluded.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class ForeignRedirects
{
    /** Rows read per table — generous for a real rule set, bounded so a log table can't swamp the response. */
    private const ROW_LIMIT = 5000;

    private const FROM_COLUMNS = ['url_from', 'from_url', 'from', 'source', 'source_url', 'old_url', 'request', 'url'];

    private const TO_COLUMNS = ['url_to', 'to_url', 'to', 'destination', 'target', 'target_url', 'new_url', 'action_data'];

    private const CODE_COLUMNS = ['type', 'code', 'status_code', 'action_code', 'http_code', 'redirect_type'];

    private const STATUS_COLUMNS = ['status', 'enabled', 'active', 'is_active'];

    private const HITS_COLUMNS = ['hits', 'last_count', 'count', 'visits', 'hit_count'];

    /**
     * @return array{rules: list<array<string, mixed>>, tables: list<array<string, mixed>>, options: list<array<string, mixed>>}
     */
    public static function collect(): array
    {
        global $wpdb;

        $rules = [];
        $tables = [];
        $options = [];

        // 1. Tables with "redirect" in the name (301 Redirects: {prefix}redirects · Redirection:
        //    {prefix}redirection_items · others).
        $names = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', '%' . $wpdb->esc_like('redirect') . '%'));
        $skipped = [];
        foreach ($names as $table) {
            $table = (string) $table;
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                continue;
            }
            // A hit / 404 log is not a rule set (301 Redirects Pro: {prefix}wf301_redirect_logs) — never read it.
            if (preg_match('/log|hit|stat|404/i', $table) === 1) {
                $skipped[] = ['table' => $table, 'why' => 'log table'];

                continue;
            }
            $columns = array_map('strval', (array) $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0));
            $rows = (array) $wpdb->get_results("SELECT * FROM `{$table}` LIMIT " . self::ROW_LIMIT, ARRAY_A);
            $parsed = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rule = self::normalize($row, $table);
                if ($rule !== null) {
                    $rules[] = $rule;
                    $parsed++;
                }
            }
            $tables[] = ['table' => $table, 'columns' => $columns, 'rows' => count($rows), 'parsed' => $parsed];
        }

        // 2. Options with "redirect" in the name (the 301 Redirects free plugin's older array form, and
        //    whatever else), excluding the companion's own map.
        $opts = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s",
            '%' . $wpdb->esc_like('redirect') . '%',
            Meta::OPTION_REDIRECTS
        ), ARRAY_A);
        foreach ($opts as $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $name = (string) $opt['option_name'];
            $value = maybe_unserialize((string) $opt['option_value']);
            $parsed = 0;
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    // from => to pairs, or a list of rows shaped like a table.
                    if (is_string($k) && is_string($v) && str_starts_with($k, '/')) {
                        $rules[] = ['from' => $k, 'to' => $v, 'code' => 301, 'hits' => null, 'source' => 'option:' . $name, 'regex' => false];
                        $parsed++;
                    } elseif (is_array($v)) {
                        $rule = self::normalize($v, 'option:' . $name);
                        if ($rule !== null) {
                            $rules[] = $rule;
                            $parsed++;
                        }
                    }
                }
            }
            $options[] = [
                'name' => $name,
                'summary' => is_array($value) ? count($value) . ' entries, ' . $parsed . ' parsed as rules' : 'scalar, ' . strlen((string) $opt['option_value']) . ' bytes',
            ];
        }

        // 3. Safe Redirect Manager — a CPT with meta.
        foreach ((array) get_posts(['post_type' => 'redirect_rule', 'post_status' => 'publish', 'numberposts' => self::ROW_LIMIT, 'suppress_filters' => false]) as $post) {
            $from = (string) get_post_meta($post->ID, '_redirect_rule_from', true);
            $to = (string) get_post_meta($post->ID, '_redirect_rule_to', true);
            if ($from === '') {
                continue;
            }
            $rules[] = [
                'from' => $from,
                'to' => $to,
                'code' => (int) (get_post_meta($post->ID, '_redirect_rule_status_code', true) ?: 301),
                'hits' => null,
                'source' => 'safe-redirect-manager',
                'regex' => (bool) get_post_meta($post->ID, '_redirect_rule_from_regex', true),
            ];
        }

        return ['rules' => $rules, 'tables' => $tables, 'options' => $options, 'skipped_tables' => $skipped];
    }

    /**
     * One table row → {from, to, code, hits, source, regex} by column name, or null when the row carries no
     * usable source path. An inactive / disabled row is dropped (the plugin was not serving it).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function normalize(array $row, string $source): ?array
    {
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        $from = self::pick($lower, self::FROM_COLUMNS);
        if ($from === null || trim((string) $from) === '') {
            return null;
        }
        $to = self::pick($lower, self::TO_COLUMNS);
        $to = is_scalar($to) ? trim((string) $to) : '';
        // Redirection stores the destination as JSON ({"url": "..."}) in newer versions.
        if ($to !== '' && $to[0] === '{') {
            $decoded = json_decode($to, true);
            $to = is_array($decoded) && is_string($decoded['url'] ?? null) ? $decoded['url'] : '';
        }

        $status = self::pick($lower, self::STATUS_COLUMNS);
        if ($status !== null && in_array(strtolower(trim((string) $status)), ['0', 'inactive', 'disabled', 'off', 'false', 'draft'], true)) {
            return null;
        }

        $code = self::pick($lower, self::CODE_COLUMNS);
        $code = is_numeric($code) ? (int) $code : 301;
        if (! in_array($code, [301, 302, 303, 307, 308, 410, 451], true)) {
            $code = 301;
        }
        $hits = self::pick($lower, self::HITS_COLUMNS);
        // The regex flag is only trusted when it is a real truthy value — plugins store "0", "no", "off"
        // or "" for a plain rule, and a loose empty() check read every one of those as "regex".
        $regex = self::truthy($lower['regex'] ?? null)
            || self::truthy($lower['is_regex'] ?? null)
            || (isset($lower['match_type']) && strtolower((string) $lower['match_type']) === 'regex');

        return [
            'from' => trim((string) $from),
            'to' => $to,
            'code' => $code,
            'hits' => is_numeric($hits) ? (int) $hits : null,
            'source' => $source,
            'regex' => $regex,
        ];
    }

    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return $v > 0;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on', 'regex'], true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $candidates
     */
    private static function pick(array $row, array $candidates): mixed
    {
        foreach ($candidates as $c) {
            if (array_key_exists($c, $row)) {
                return $row[$c];
            }
        }

        return null;
    }
}
