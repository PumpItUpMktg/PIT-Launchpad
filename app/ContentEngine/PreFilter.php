<?php

namespace App\ContentEngine;

use App\Integrations\News\NewsItem;

/**
 * The cheap rule-based pre-filter that runs before any LLM cost: drops obvious
 * junk and empty items.
 */
class PreFilter
{
    private const JUNK = ['sponsored', 'advertisement', '[ad]', 'press release', 'classifieds', 'horoscope'];

    public function passes(NewsItem $item): bool
    {
        if (mb_strlen(trim($item->title)) < 8) {
            return false;
        }

        if (trim($item->summary) === '' && trim($item->body ?? '') === '') {
            return false;
        }

        $haystack = mb_strtolower($item->title);
        foreach (self::JUNK as $junk) {
            if (str_contains($haystack, $junk)) {
                return false;
            }
        }

        return true;
    }
}
