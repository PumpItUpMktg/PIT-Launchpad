<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\ForeignRedirects;
use Launchpad\Companion\Content\RedirectStore;

class Test_Foreign_Redirects extends WP_UnitTestCase
{
    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}redirects`");
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}redirection_items`");
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}wf301_redirect_rules`");
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}wf301_redirect_logs`");
        delete_option('301_redirects');
        parent::tear_down();
    }

    public function test_reads_301_redirects_table_rows_and_skips_inactive_ones(): void
    {
        global $wpdb;
        $t = $wpdb->prefix . 'redirects';
        $wpdb->query("CREATE TABLE `{$t}` (id int auto_increment primary key, url_from varchar(255), url_to varchar(255), type varchar(8), status varchar(16), hits int)");
        $wpdb->insert($t, ['url_from' => '/old-page/', 'url_to' => '/new-page/', 'type' => '301', 'status' => 'active', 'hits' => 12]);
        $wpdb->insert($t, ['url_from' => '/temp/', 'url_to' => 'https://elsewhere.example/x', 'type' => '302', 'status' => 'active', 'hits' => 0]);
        $wpdb->insert($t, ['url_from' => '/off/', 'url_to' => '/never/', 'type' => '301', 'status' => 'inactive', 'hits' => 0]);

        $r = ForeignRedirects::collect();

        $rules = array_values(array_filter($r['rules'], static fn (array $x): bool => $x['source'] === $t));
        $this->assertCount(2, $rules);
        $this->assertSame(['from' => '/old-page/', 'to' => '/new-page/', 'code' => 301, 'hits' => 12, 'source' => $t, 'regex' => false], $rules[0]);
        $this->assertSame(302, $rules[1]['code']);
        $table = array_values(array_filter($r['tables'], static fn (array $x): bool => $x['table'] === $t))[0];
        $this->assertSame(3, $table['rows']);
        $this->assertSame(2, $table['parsed']);
    }

    public function test_reads_redirection_items_with_a_json_destination_and_flags_regex_rules(): void
    {
        global $wpdb;
        $t = $wpdb->prefix . 'redirection_items';
        $wpdb->query("CREATE TABLE `{$t}` (id int auto_increment primary key, url varchar(255), match_type varchar(16), action_data text, action_code int, status varchar(16), last_count int, regex tinyint)");
        $wpdb->insert($t, ['url' => '/services/old/', 'match_type' => 'url', 'action_data' => '{"url":"/services/new/"}', 'action_code' => 301, 'status' => 'enabled', 'last_count' => 3, 'regex' => 0]);
        $wpdb->insert($t, ['url' => '^/blog/(.*)$', 'match_type' => 'regex', 'action_data' => '/news/$1', 'action_code' => 301, 'status' => 'enabled', 'last_count' => 0, 'regex' => 1]);

        $rules = array_values(array_filter(ForeignRedirects::collect()['rules'], static fn (array $x): bool => $x['source'] === $t));

        $this->assertCount(2, $rules);
        $this->assertSame('/services/new/', $rules[0]['to']);
        $this->assertFalse($rules[0]['regex']);
        $this->assertTrue($rules[1]['regex']);
    }

    public function test_reads_an_option_of_from_to_pairs_and_never_the_companions_own_map(): void
    {
        update_option('301_redirects', ['/a/' => '/b/', '/c/' => '/d/'], false);
        ( new RedirectStore() )->upsert([['from_url' => '/ours/', 'to_url' => '/theirs/', 'code' => 301]]);

        $r = ForeignRedirects::collect();

        $fromOption = array_values(array_filter($r['rules'], static fn (array $x): bool => $x['source'] === 'option:301_redirects'));
        $this->assertCount(2, $fromOption);
        $this->assertSame('/b/', $fromOption[0]['to']);
        $this->assertSame([], array_filter($r['rules'], static fn (array $x): bool => $x['from'] === '/ours/'));
        $this->assertSame([], array_filter($r['options'], static fn (array $x): bool => $x['name'] === 'lp_redirects'));
    }

    public function test_skips_a_log_table_and_does_not_read_a_no_or_zero_regex_flag_as_regex(): void
    {
        global $wpdb;
        $rules = $wpdb->prefix . 'wf301_redirect_rules';
        $logs = $wpdb->prefix . 'wf301_redirect_logs';
        $wpdb->query("CREATE TABLE `{$rules}` (id int auto_increment primary key, url_from varchar(255), url_to varchar(255), type varchar(8), status varchar(16), regex varchar(8), hits int)");
        $wpdb->query("CREATE TABLE `{$logs}` (id int auto_increment primary key, url_from varchar(255), status varchar(16), hits int)");
        $wpdb->insert($rules, ['url_from' => '/west-goshen-pa', 'url_to' => '/downingtown-pa/west-goshen-pa', 'type' => '301', 'status' => 'active', 'regex' => 'no', 'hits' => 5]);
        $wpdb->insert($rules, ['url_from' => '/hoboken', 'url_to' => '/hoboken-nj', 'type' => '301', 'status' => 'active', 'regex' => '0', 'hits' => 5]);
        $wpdb->insert($rules, ['url_from' => '^/blog/(.*)$', 'url_to' => '/news/$1', 'type' => '301', 'status' => 'active', 'regex' => 'yes', 'hits' => 0]);
        $wpdb->insert($logs, ['url_from' => '/login', 'status' => '404', 'hits' => 900]);

        $r = ForeignRedirects::collect();

        $fromRules = array_values(array_filter($r['rules'], static fn (array $x): bool => $x['source'] === $rules));
        $this->assertCount(3, $fromRules);
        $this->assertFalse($fromRules[0]['regex']);
        $this->assertFalse($fromRules[1]['regex']);
        $this->assertTrue($fromRules[2]['regex']);
        $this->assertSame([], array_filter($r['rules'], static fn (array $x): bool => $x['source'] === $logs));
        $this->assertSame([['table' => $logs, 'why' => 'log table']], $r['skipped_tables']);
    }
}
