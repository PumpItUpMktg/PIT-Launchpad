<?php

namespace App\Reviews\Intake;

use App\Reviews\Intake\Contracts\JobSource;
use Illuminate\Support\Carbon;

/**
 * The source-agnostic completed-job payload every review request originates from (Review Capture §3). Joby,
 * operator entry, and any future driver all map INTO this shape — so no external system's field names ever
 * appear outside a {@see JobSource} driver.
 *
 * Tenancy note: the platform's tenant is a Site, so the tenant key is `siteId` (the spec's `tenant_id`). The
 * customer name is split into first name + last initial and only ever rendered as "First L." (never fuller).
 * `serviceAddress` is internal-only audit data — never rendered below city level. `locationId` and `serviceIds`
 * are the SYSTEM's resolution (§4), carried here once resolved; a null `locationId` means the town belonged to
 * no Location and the review must be flagged `needs_location`, never guessed.
 */
final class CompletedJob
{
    /**
     * @param  list<string>  $serviceIds  resolved Service ids (tenant silo structure), capped downstream at 3
     */
    public function __construct(
        public readonly string $siteId,
        public readonly ?string $externalRef,
        public readonly string $customerFirstName,
        public readonly string $customerLastInitial,
        public readonly string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly string $serviceAddress,
        public readonly ?string $locationId,
        public readonly array $serviceIds,
        public readonly Carbon $completedAt,
    ) {}

    /** The only rendered form of the customer's name: "First L." (matching Job Capture). */
    public function displayName(): string
    {
        $initial = $this->customerLastInitial !== ''
            ? ' '.mb_strtoupper(mb_substr($this->customerLastInitial, 0, 1)).'.'
            : '';

        return trim($this->customerFirstName.$initial);
    }
}
