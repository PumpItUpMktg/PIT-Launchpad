<?php

namespace App\Enums;

/**
 * The per-tenant credential kinds the rotation tooling operates on. Each maps to
 * the Connection provider that stores it, so a rotation command can resolve the
 * target Connection from a credential type.
 */
enum CredentialType: string
{
    case WpAppPassword = 'wp_app_password';
    case GbpToken = 'gbp_token';
    case Ga4Token = 'ga4_token';
    case GhlToken = 'ghl_token';

    /**
     * The Connection provider that stores this credential.
     */
    public function provider(): ConnectionProvider
    {
        return match ($this) {
            self::WpAppPassword => ConnectionProvider::WpAppPassword,
            self::GbpToken => ConnectionProvider::Gbp,
            self::Ga4Token => ConnectionProvider::Ga4,
            self::GhlToken => ConnectionProvider::Ghl,
        };
    }
}
