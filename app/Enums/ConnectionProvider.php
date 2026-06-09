<?php

namespace App\Enums;

enum ConnectionProvider: string
{
    case Gbp = 'gbp';
    // One Google OAuth grant covers both GSC (§5) and GA4 (§7c); a single
    // per-site `google` connection holds the shared tokens + both property IDs.
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
