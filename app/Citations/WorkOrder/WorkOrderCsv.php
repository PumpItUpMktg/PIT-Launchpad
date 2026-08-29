<?php

namespace App\Citations\WorkOrder;

/**
 * Renders a {@see WorkOrder} to CSV (§ Citations, PR6) — the bulk-friendly face of the work order. Every row
 * is self-contained (the canonical NAP repeated on each line) so a VA working the sheet has the full
 * submission payload without cross-referencing.
 */
final class WorkOrderCsv
{
    private const HEADERS = [
        'action', 'directory', 'domain', 'submission_url', 'submission_method', 'requires_client_action',
        'recommendation', 'seo_value', 'cost', 'turnaround_days', 'fields_to_fix',
        'business_name', 'address_1', 'address_2', 'city', 'state', 'postal', 'phone', 'website', 'verification_email',
    ];

    public function render(WorkOrder $order): string
    {
        $nap = $order->nap;
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::HEADERS);
        foreach ($order->lines as $line) {
            fputcsv($handle, [
                $line->actionLabel(),
                $line->directoryName,
                $line->domain,
                $line->submissionUrl ?? '',
                $line->submissionMethod ?? '',
                $line->requiresClientAction ? 'yes' : 'no',
                $line->recommendation->value,
                (string) $line->seoValue,
                $line->cost !== null ? number_format($line->cost, 2) : '',
                $line->turnaroundDays !== null ? (string) $line->turnaroundDays : '',
                $this->fieldsToFix($line->mismatchFields),
                (string) ($nap['business_name'] ?? ''),
                (string) ($nap['address_1'] ?? ''),
                (string) ($nap['address_2'] ?? ''),
                (string) ($nap['city'] ?? ''),
                (string) ($nap['state'] ?? ''),
                (string) ($nap['postal'] ?? ''),
                (string) ($nap['phone_primary'] ?? ''),
                (string) ($nap['website_url'] ?? ''),
                (string) ($nap['verification_email'] ?? ''),
            ]);
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @param  array<string, array{found: mixed, expected: mixed}>|null  $mismatchFields
     */
    private function fieldsToFix(?array $mismatchFields): string
    {
        if ($mismatchFields === null || $mismatchFields === []) {
            return '';
        }

        return implode('; ', array_keys($mismatchFields));
    }
}
