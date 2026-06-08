<?php

namespace App\Enums;

enum SourceType: string
{
    case RssFeed = 'rss_feed';
    case KeywordGap = 'keyword_gap';
    case Manual = 'manual';
}
