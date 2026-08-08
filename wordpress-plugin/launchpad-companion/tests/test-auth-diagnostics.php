<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Rest\AuthDiagnostics;

class Test_Auth_Diagnostics extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        parent::tearDown();
    }

    public function test_reports_header_absent_when_no_authorization_arrives(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $p = AuthDiagnostics::payload();

        $this->assertFalse($p['authorization_received']);
        $this->assertSame('none', $p['scheme']);
        $this->assertNull($p['username']);
        $this->assertSame(LPC_VERSION, $p['plugin_version']);
    }

    public function test_reports_basic_scheme_and_reflects_username_without_password(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('launchpad-sync:abcd efgh ijkl');

        $p = AuthDiagnostics::payload();

        $this->assertTrue($p['authorization_received']);
        $this->assertSame('basic', $p['scheme']);
        $this->assertSame('launchpad-sync', $p['username']);
        // The password is never echoed back anywhere in the payload.
        $this->assertStringNotContainsString('abcd', wp_json_encode($p));
    }

    public function test_reads_redirect_fallback_header(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('user:pw');

        $p = AuthDiagnostics::payload();

        $this->assertTrue($p['authorization_received']);
        $this->assertSame('user', $p['username']);
    }

    public function test_reports_environment_flags(): void
    {
        $p = AuthDiagnostics::payload();

        $this->assertArrayHasKey('application_passwords_available', $p);
        $this->assertArrayHasKey('is_ssl', $p);
        $this->assertIsBool($p['is_ssl']);
    }
}
