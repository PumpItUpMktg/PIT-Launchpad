<?php

namespace App\Interview;

/**
 * The silo seed extracted from the owner interview: the trade, a few anchor
 * services (NOT exhaustive — the owner confirms/vetoes the expansion, never
 * enumerates), the service-area markets, and explicit exclusions. `gbpSignals`
 * carries the connected Google Business Profile categories/services used to ground
 * the extraction (null when GBP was not connected — description-only is the floor).
 *
 * Immutable value object; round-trips to/from array so the wizard (a later PR) can
 * persist it. PR #1 emits it headless and never stores it.
 */
final class SiloSeed
{
    /**
     * @param  list<string>  $anchorServices
     * @param  list<string>  $markets
     * @param  list<string>  $exclusions
     * @param  list<string>|null  $gbpSignals
     */
    public function __construct(
        public readonly string $trade,
        public readonly array $anchorServices = [],
        public readonly array $markets = [],
        public readonly array $exclusions = [],
        public readonly ?array $gbpSignals = null,
    ) {}

    /**
     * @param  list<string>|null  $gbpSignals
     */
    public function withGbpSignals(?array $gbpSignals): self
    {
        return new self(
            $this->trade,
            $this->anchorServices,
            $this->markets,
            $this->exclusions,
            $gbpSignals,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trade' => $this->trade,
            'anchor_services' => $this->anchorServices,
            'markets' => $this->markets,
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
            markets: self::stringList($data['markets'] ?? []),
            exclusions: self::stringList($data['exclusions'] ?? []),
            gbpSignals: isset($data['gbp_signals']) && is_array($data['gbp_signals'])
                ? self::stringList($data['gbp_signals'])
                : null,
        );
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
