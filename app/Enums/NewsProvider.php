<?php

namespace App\Enums;

/**
 * Selects the live news source behind the §6a NewsProvider contract. GDELT is
 * the default (no key, ~3-month rolling window); NewsAPI is the configured
 * alternate (keyed, real pagination, paid in production). Chosen by NEWS_PROVIDER.
 */
enum NewsProvider: string
{
    case Gdelt = 'gdelt';
    case NewsApi = 'newsapi';
}
