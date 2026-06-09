<?php

namespace App\Integrations\Google;

use App\Models\Site;
use DateTimeInterface;

/**
 * Programmable GSC source for tests and the default binding (no §5 consumer yet).
 * Returns the rows it was given.
 */
class MockSearchConsoleProvider implements SearchConsoleProvider
{
    /** @var list<SearchAnalyticsRow> */
    private array $rows = [];

    /**
     * @param  list<SearchAnalyticsRow>  $rows
     */
    public function withRows(array $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * @param  list<string>  $dimensions
     * @return list<SearchAnalyticsRow>
     */
    public function searchAnalytics(
        Site $site,
        DateTimeInterface $start,
        DateTimeInterface $end,
        array $dimensions = ['query'],
        int $rowLimit = 1000,
    ): array {
        return $this->rows;
    }
}
