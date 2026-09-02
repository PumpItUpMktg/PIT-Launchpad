<?php

namespace App\Reviews\Intake;

use App\Models\Job;
use App\Reviews\Intake\Contracts\JobSource;
use Illuminate\Support\Carbon;

/**
 * The v1 driver: an operator issues each review request by hand — either from an existing Job Capture record or
 * from pasted customer details (Review Capture §3). It is PUSH, not poll, so {@see pending()} is empty by
 * design; the operator Filament action (a later PR) calls {@see fromJob()} / {@see fromDetails()} to build the
 * payload. This driver is permanent — the fallback whenever an upstream integration drops an event — not a
 * stopgap.
 *
 * This class is the ONLY place a Job's field shape maps into the module. `locationId` and `serviceIds` are the
 * system's resolution (§4) and are passed in already resolved — they are not this driver's to invent.
 */
final class ManualJobSource implements JobSource
{
    /**
     * Manual is push: there is no backlog to pull, so this is always empty. A polling driver (e.g. a future
     * Joby webhook buffer) is what returns rows here.
     *
     * @return iterable<CompletedJob>
     */
    public function pending(): iterable
    {
        return [];
    }

    /**
     * Map an existing Job Capture record → CompletedJob. The Job supplies name / address / date; the operator
     * supplies the customer email + phone (Job Capture doesn't store them) and the system supplies the resolved
     * location + services.
     *
     * @param  list<string>  $serviceIds
     */
    public function fromJob(Job $job, string $email, ?string $phone, ?string $locationId, array $serviceIds): CompletedJob
    {
        [$first, $initial] = $this->splitName((string) $job->client_name_full);

        return new CompletedJob(
            siteId: (string) $job->site_id,
            externalRef: (string) $job->id, // the job record is this manual review's external reference
            customerFirstName: $first,
            customerLastInitial: $initial,
            customerEmail: $email,
            customerPhone: $phone !== null && $phone !== '' ? $phone : null,
            serviceAddress: (string) ($job->address_true ?? ''),
            locationId: $locationId,
            serviceIds: $serviceIds,
            completedAt: $job->performed_at !== null ? Carbon::parse((string) $job->performed_at) : Carbon::now(),
        );
    }

    /**
     * Map pasted operator details → CompletedJob.
     *
     * @param  array{site_id: string, customer_name?: string, customer_first_name?: string, customer_last_initial?: string, customer_email: string, customer_phone?: ?string, service_address?: string, external_ref?: ?string, location_id?: ?string, service_ids?: list<string>, completed_at?: ?string}  $details
     */
    public function fromDetails(array $details): CompletedJob
    {
        [$first, $initial] = $this->splitName(trim((string) ($details['customer_name'] ?? '')));

        return new CompletedJob(
            siteId: (string) $details['site_id'],
            externalRef: isset($details['external_ref']) && $details['external_ref'] !== ''
                ? (string) $details['external_ref'] : null,
            customerFirstName: (string) ($details['customer_first_name'] ?? $first),
            customerLastInitial: (string) ($details['customer_last_initial'] ?? $initial),
            customerEmail: (string) $details['customer_email'],
            customerPhone: isset($details['customer_phone']) && $details['customer_phone'] !== ''
                ? (string) $details['customer_phone'] : null,
            serviceAddress: (string) ($details['service_address'] ?? ''),
            locationId: isset($details['location_id']) && $details['location_id'] !== '' ? (string) $details['location_id'] : null,
            serviceIds: $details['service_ids'] ?? [],
            completedAt: isset($details['completed_at']) && $details['completed_at'] !== ''
                ? Carbon::parse((string) $details['completed_at']) : Carbon::now(),
        );
    }

    /**
     * Split a full name into first name + last initial (a single upper letter, no dot). One-word names get no
     * initial. The "First L." rendering adds the dot ({@see CompletedJob::displayName()}).
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $full): array
    {
        $full = trim($full);
        if ($full === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $full) ?: [$full];
        $first = (string) $parts[0];
        $initial = count($parts) > 1 ? mb_strtoupper(mb_substr((string) end($parts), 0, 1)) : '';

        return [$first, $initial];
    }
}
