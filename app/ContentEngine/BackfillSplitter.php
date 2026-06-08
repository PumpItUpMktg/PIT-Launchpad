<?php

namespace App\ContentEngine;

use App\Integrations\News\NewsItem;
use DateTimeImmutable;

/**
 * Splits backfill history at the freshness cutoff: items older than the cutoff
 * feed the silo-discovery corpus (never auto-drafted); newer items flow through
 * the normal candidate pipeline.
 */
class BackfillSplitter
{
    /**
     * @param  list<NewsItem>  $items
     * @return array{recent: list<NewsItem>, archive: list<NewsItem>}
     */
    public function split(array $items, int $cutoffDays): array
    {
        $cutoff = (new DateTimeImmutable)->modify("-{$cutoffDays} days");

        $recent = [];
        $archive = [];

        foreach ($items as $item) {
            if ($item->publishedAt >= $cutoff) {
                $recent[] = $item;
            } else {
                $archive[] = $item;
            }
        }

        return ['recent' => $recent, 'archive' => $archive];
    }
}
