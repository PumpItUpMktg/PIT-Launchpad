<?php

namespace App\Enums;

/**
 * Selects the live news source behind the §6a NewsProvider contract.
 *
 *  - googlenews (default): Google News RSS, consent-aware fetch — beats the
 *    datacenter-IP wall GDELT hits.
 *  - gdelt: GDELT DOC 2.0 (no key) — parked alternate; throttled on datacenter IPs.
 *  - newsapi: keyed alternate (paid in production).
 *
 * Chosen by NEWS_PROVIDER.
 */
enum NewsProvider: string
{
    case GoogleNews = 'googlenews';
    case Gdelt = 'gdelt';
    case NewsApi = 'newsapi';
}
