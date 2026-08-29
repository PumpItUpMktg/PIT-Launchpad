<?php

namespace App\Citations\WorkOrder;

use App\Enums\CitationState;
use App\Enums\DirectoryRecommendation;

/**
 * One directory a VA should act on in a citation work order (§ Citations, PR6). Carries the submission target
 * and instructions; the canonical NAP the VA submits lives once on the {@see WorkOrder} header (it's identical
 * for every line).
 */
final readonly class WorkOrderLine
{
    /**
     * @param  array<string, array{found: mixed, expected: mixed}>|null  $mismatchFields  what to correct (needs_fix only)
     */
    public function __construct(
        public string $statusId,
        public string $directoryName,
        public string $domain,
        public CitationState $action,            // not_listed (create) | needs_fix (correct)
        public DirectoryRecommendation $recommendation,
        public int $seoValue,
        public ?float $cost,
        public ?string $submissionMethod,
        public ?string $submissionUrl,
        public bool $requiresClientAction,
        public ?int $turnaroundDays,
        public ?array $mismatchFields = null,
    ) {}

    /** The instruction verb a VA reads: create a new listing, or correct the existing one. */
    public function actionLabel(): string
    {
        return $this->action === CitationState::NeedsFix ? 'Correct listing' : 'Create listing';
    }
}
