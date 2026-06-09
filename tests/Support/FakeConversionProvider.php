<?php

namespace Tests\Support;

use App\Enums\ConversionSource;
use App\Integrations\Conversions\ConversionProvider;
use App\Integrations\Conversions\ConversionRecord;
use App\Models\Site;
use DateTimeInterface;
use RuntimeException;

/**
 * A programmable ConversionProvider for ingest tests: returns canned records (or
 * throws, to exercise per-provider failure isolation) and records the `$since`
 * cursor it was called with.
 */
class FakeConversionProvider implements ConversionProvider
{
    /** @var list<DateTimeInterface> */
    public array $calledWith = [];

    /**
     * @param  list<ConversionRecord>  $records
     */
    public function __construct(
        private readonly ConversionSource $source,
        private readonly array $records = [],
        private readonly ?string $throw = null,
    ) {}

    public function source(): ConversionSource
    {
        return $this->source;
    }

    public function pull(Site $site, DateTimeInterface $since): array
    {
        $this->calledWith[] = $since;

        if ($this->throw !== null) {
            throw new RuntimeException($this->throw);
        }

        return $this->records;
    }
}
