<?php

namespace App\Interview;

/**
 * The silo seed extracted from the owner interview: the trade, a few anchor
 * services (NOT exhaustive — the owner confirms/vetoes the expansion, never
 * enumerates), the BROAD region they serve (positioning only — a short phrase like
 * "NJ, eastern PA", NOT a town-by-town list; specific service areas are the
 * authoritative Locations layer, not this field), and explicit exclusions.
 * `gbpSignals` carries the connected Google Business Profile categories/services used
 * to ground the extraction (null when GBP was not connected — description-only is the
 * floor).
 *
 * Immutable value object; round-trips to/from array so the wizard can persist it.
 */
final class SiloSeed
{
    /**
     * @param  list<string>  $anchorServices
     * @param  list<string>  $exclusions
     * @param  list<string>|null  $gbpSignals
     * @param  list<string>  $boundedServices  the COMPLETE stated-service list when generation is bound
     *                                         to it (empty ⇒ generous mode: expand broad, prune later)
     */
    public function __construct(
        public readonly string $trade,
        public readonly array $anchorServices = [],
        public readonly string $region = '',
        public readonly array $exclusions = [],
        public readonly ?array $gbpSignals = null,
        public readonly array $boundedServices = [],
    ) {}

    /**
     * @param  list<string>|null  $gbpSignals
     */
    public function withGbpSignals(?array $gbpSignals): self
    {
        return new self(
            $this->trade,
            $this->anchorServices,
            $this->region,
            $this->exclusions,
            $gbpSignals,
            $this->boundedServices,
        );
    }

    /**
     * A copy bound to the COMPLETE stated-service list — the expander then organizes ONLY these into
     * silos and never invents a service outside the list.
     *
     * @param  list<string>  $services
     */
    public function withBoundedServices(array $services): self
    {
        return new self(
            $this->trade,
            $this->anchorServices,
            $this->region,
            $this->exclusions,
            $this->gbpSignals,
            $services,
        );
    }

    /** Whether generation is bound to a stated-service list (vs generous expansion). */
    public function isBounded(): bool
    {
        return $this->boundedServices !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trade' => $this->trade,
            'anchor_services' => $this->anchorServices,
            'region' => $this->region,
            'exclusions' => $this->exclusions,
            'gbp_signals' => $this->gbpSignals,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            trade: trim((string) ($data['trade'] ?? '')),
            anchorServices: self::stringList($data['anchor_services'] ?? []),
            region: self::regionString($data),
            exclusions: self::stringList($data['exclusions'] ?? []),
            gbpSignals: isset($data['gbp_signals']) && is_array($data['gbp_signals'])
                ? self::stringList($data['gbp_signals'])
                : null,
        );
    }

    /**
     * The broad region as a single phrase. Backward-compatible with seeds stored
     * under the old `markets` list (joined to a phrase) before the region reframe.
     *
     * @param  array<string, mixed>  $data
     */
    private static function regionString(array $data): string
    {
        if (array_key_exists('region', $data)) {
            return is_string($data['region']) ? trim($data['region']) : '';
        }

        return implode(', ', self::stringList($data['markets'] ?? []));
    }

    /**
     * Normalize a mixed value to a list of trimmed, non-empty strings.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_numeric($item)) {
                $trimmed = trim((string) $item);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
