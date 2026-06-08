<?php

namespace App\Enums;

enum ConnectionProvider: string
{
    case Gbp = 'gbp';
    case Ga4 = 'ga4';
    case Gtm = 'gtm';
    case Ghl = 'ghl';
    case Dataforseo = 'dataforseo';
    case LocalFalcon = 'local_falcon';
    case Fal = 'fal';
    case Anthropic = 'anthropic';
    case WpAppPassword = 'wp_app_password';
}
