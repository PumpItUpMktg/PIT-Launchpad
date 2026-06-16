<?php

namespace App\Enums;

/**
 * The page type backing a blueprint spoke. A confirmed offering (or a "would add
 * it") becomes a service page; a "no, but capture the upstream searcher" becomes a
 * content-path page (the new evergreen guide page-type).
 */
enum SpokePageType: string
{
    case Service = 'service';
    case Content = 'content';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Service page',
            self::Content => 'Content-path guide',
        };
    }
}
