<?php
/**
 * Read-only auth-arrival diagnostics for the PUBLIC launchpad/v1/auth-check endpoint. It answers the
 * one question the control plane can't see from its side of the wire: did the `Authorization` header
 * actually SURVIVE the trip to WordPress? A control-plane 401 has two very different causes that look
 * identical from outside — (a) an edge/host stripped the header before WordPress (Cloudflare, or
 * nginx / FastCGI / some managed hosts), so WordPress saw an anonymous request; or (b) the header
 * arrived and the Application Password was genuinely wrong. This reports which, plus the environment
 * flags that gate Application Passwords. No secret is returned (never the password), and it is no more
 * of an auth oracle than WordPress's own Basic-auth 401/200 already is.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Rest;

if (! defined('ABSPATH')) {
    exit;
}

final class AuthDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $header = self::incoming_authorization();
        [$scheme, $username] = self::parse($header);

        return [
            // The headline signal: false means the header never reached WordPress (stripped in transit).
            'authorization_received' => $header !== '',
            'scheme' => $scheme,
            // The username the caller sent (reflected — never the password), so the operator can confirm
            // the connect form is targeting the intended user.
            'username' => $username,
            // Application Passwords require HTTPS and can be turned off by a security plugin/filter; when
            // false, even a correct header/password 401s.
            'application_passwords_available' => function_exists('wp_is_application_passwords_available')
                ? (bool) wp_is_application_passwords_available()
                : null,
            'is_ssl' => function_exists('is_ssl') ? is_ssl() : false,
            'plugin_version' => defined('LPC_VERSION') ? LPC_VERSION : null,
        ];
    }

    /** The incoming Authorization header, checked across the places servers stash it (empty when absent). */
    private static function incoming_authorization(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (! empty($_SERVER[$key])) {
                return trim((string) $_SERVER[$key]);
            }
        }

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0 && (string) $value !== '') {
                    return trim((string) $value);
                }
            }
        }

        return '';
    }

    /**
     * @return array{0: string, 1: ?string} [scheme, basic-username|null]
     */
    private static function parse(string $header): array
    {
        if ($header === '') {
            return ['none', null];
        }

        if (stripos($header, 'basic ') === 0) {
            $decoded = base64_decode(trim(substr($header, 6)), true);
            $username = (is_string($decoded) && strpos($decoded, ':') !== false)
                ? substr($decoded, 0, (int) strpos($decoded, ':'))
                : null;

            return ['basic', $username];
        }

        if (stripos($header, 'bearer ') === 0) {
            return ['bearer', null];
        }

        return ['other', null];
    }
}
