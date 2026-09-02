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

    /**
     * Serialize for the review_requests.payload snapshot.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'external_ref' => $this->externalRef,
            'customer_first_name' => $this->customerFirstName,
            'customer_last_initial' => $this->customerLastInitial,
            'customer_email' => $this->customerEmail,
            'customer_phone' => $this->customerPhone,
            'service_address' => $this->serviceAddress,
            'location_id' => $this->locationId,
            'service_ids' => $this->serviceIds,
            'completed_at' => $this->completedAt->toIso8601String(),
        ];
    }

    /**
     * Rebuild from a stored payload snapshot.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            siteId: (string) $data['site_id'],
            externalRef: isset($data['external_ref']) ? (string) $data['external_ref'] : null,
            customerFirstName: (string) ($data['customer_first_name'] ?? ''),
            customerLastInitial: (string) ($data['customer_last_initial'] ?? ''),
            customerEmail: (string) ($data['customer_email'] ?? ''),
            customerPhone: isset($data['customer_phone']) ? (string) $data['customer_phone'] : null,
            serviceAddress: (string) ($data['service_address'] ?? ''),
            locationId: isset($data['location_id']) ? (string) $data['location_id'] : null,
            serviceIds: array_values($data['service_ids'] ?? []),
            completedAt: Carbon::parse((string) $data['completed_at']),
        );
    }
}
