<?php

namespace App\Citations\Ui;

/**
 * The client-readable citation report for one location (§ Citations UI, PR E). Plain-language only — four
 * headline counts, the corrections in "currently shows → should show" terms, and the directories still
 * available to add. No enum names, no internal vocabulary, no bare percentages: the client is good at their
 * trade and has no interest in the plumbing behind it.
 *
 * @property list<array{directory: string, fields: list<array{field: string, found: string, expected: string}>}> $corrections
 * @property list<string> $available
 */
final readonly class CitationReportData
{
    /**
     * @param  list<array{directory: string, fields: list<array{field: string, found: string, expected: string}>}>  $corrections
     * @param  list<string>  $available
     */
    public function __construct(
        public string $locationName,
        public int $listedCorrectly,
        public int $wrongInformation,
        public int $beingAdded,
        public int $stillAvailable,
        public array $corrections,
        public array $available,
    ) {}
}
