<?php

namespace App\Integrations\Conversions;

use Illuminate\Contracts\Container\Container;

/**
 * The set of active conversion providers (GA4 + Krayin + Mautic), resolved from
 * the `conversion.providers` container tag. A thin indirection the ingest job
 * depends on so tests can supply an exact provider set.
 */
class ConversionProviders
{
    /**
     * @param  list<ConversionProvider>|null  $providers  explicit set (tests); null = the tagged set
     */
    public function __construct(
        private readonly ?array $providers = null,
        private readonly ?Container $app = null,
    ) {}

    /**
     * @return list<ConversionProvider>
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        /** @var list<ConversionProvider> $tagged */
        $tagged = array_values(iterator_to_array($this->app?->tagged('conversion.providers') ?? []));

        return $tagged;
    }
}
