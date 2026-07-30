<?php

namespace App\Enums;

enum ConnectionProvider: string
{
    case Gbp = 'gbp';
    // Google (GSC + GA4) is now a PLATFORM-shared grant, not a per-tenant credential: the one token
    // lives on the GoogleAccount singleton and each Site stores only which property to read. This enum
    // case is retained for back-compat / any legacy rows; the shared grant is not a `connections` row.
    case Google = 'google';
    case Ga4 = 'ga4';
    case Gtm = 'gtm';
    case Ghl = 'ghl';
    case Dataforseo = 'dataforseo';
    case LocalFalcon = 'local_falcon';
    case Fal = 'fal';
    case Anthropic = 'anthropic';
    case WpAppPassword = 'wp_app_password';
}
