<?php

namespace App\Locations\Reconcile;

/**
 * One planned (or applied) fold of a duplicate physical location into a survivor row: which row wins,
 * which is retired, which GBP fields got back-filled onto the survivor, and how many Content pins were
 * re-pointed. A value object so the dry-run can print exactly what `--force` will do.
 */
final class LocationMerge
{
    /**
     * @param  list<string>  $backfilled  survivor fields filled from the duplicate
     */
    public function __construct(
        public readonly string $survivorId,
        public readonly string $survivorName,
        public readonly string $dupeId,
        public readonly string $dupeName,
        public readonly string $matchedOn,
        public readonly array $backfilled,
        public readonly int $contentRepointed,
    ) {}

    public function summary(): string
    {
        $fields = $this->backfilled === [] ? 'no new fields' : implode(', ', $this->backfilled);

        return sprintf(
            '"%s" (%s) ← fold "%s" (%s) · matched on %s · back-fill: %s · %d page(s) re-pointed',
            $this->survivorName, $this->survivorId, $this->dupeName, $this->dupeId,
            $this->matchedOn, $fields, $this->contentRepointed,
        );
    }
}
