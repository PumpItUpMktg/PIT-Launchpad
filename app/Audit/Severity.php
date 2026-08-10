<?php

namespace App\Audit;

/**
 * The three severities an {@see AuditCheck} can carry, and the ranking the `--fail-on` gate uses.
 * Kept as bare string constants (not an enum) so a check declares `Severity::Critical` and the runner
 * compares ranks without ceremony.
 */
final class Severity
{
    public const Critical = 'critical';

    public const High = 'high';

    public const Medium = 'medium';

    /** @var array<string, int> */
    private const RANK = [self::Medium => 1, self::High => 2, self::Critical => 3];

    public static function rank(string $severity): int
    {
        return self::RANK[$severity] ?? 0;
    }

    /**
     * Whether a finding of $severity trips a `--fail-on=$threshold` gate. `any` trips on anything;
     * otherwise the finding must rank at or above the threshold.
     */
    public static function trips(string $severity, string $threshold): bool
    {
        if ($threshold === 'any') {
            return true;
        }

        return self::rank($severity) >= self::rank($threshold);
    }
}
