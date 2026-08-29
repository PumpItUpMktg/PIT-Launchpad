<?php

namespace App\Citations\WorkOrder;

use App\Citations\DirectoryRating;
use App\Enums\CitationState;
use App\Enums\DirectoryRecommendation;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use Illuminate\Support\Carbon;

/**
 * Builds a location's citation work order (§ Citations, PR6): the actionable gaps (not-listed / needs-fix),
 * ordered by the directory's operator verdict then SEO value, rendered against the canonical NAP so every
 * submission is byte-identical. Low-value and skip-paid directories are left out (not worth a VA's time this
 * batch), and paid directories beyond the tenant's budget ceiling are deferred to the next batch — so the
 * work order is always the highest-leverage work that fits the money.
 */
final class WorkOrderBuilder
{
    public function __construct(private readonly DirectoryRating $rating = new DirectoryRating) {}

    public function build(Location $location, ?float $paidBudget = null): WorkOrder
    {
        $paidBudget ??= (float) config('launchpad.citations.work_order_paid_budget', 100.0);

        $profile = LocationNapProfile::query()->where('location_id', $location->id)->first();
        $nap = $profile !== null ? $this->napSnapshot($profile) : [];

        $statuses = CitationStatus::query()
            ->where('location_id', $location->id)
            ->whereIn('state', [CitationState::NotListed->value, CitationState::NeedsFix->value])
            ->get();

        // Score + filter to actionable directories, then order by verdict priority, value, domain.
        $candidates = [];
        foreach ($statuses as $status) {
            $directory = Directory::query()->find($status->directory_id);
            if ($directory === null || ! $directory->is_active) {
                continue;
            }
            $recommendation = $this->rating->recommendation($directory);
            if (! $recommendation->isActionable()) {
                continue; // low_value / skip_paid — backlog, not this batch
            }
            $candidates[] = [
                'status' => $status,
                'directory' => $directory,
                'recommendation' => $recommendation,
                'value' => $this->rating->seoValue($directory),
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            return [$this->priority($a['recommendation']), -$a['value'], $a['directory']->domain]
                <=> [$this->priority($b['recommendation']), -$b['value'], $b['directory']->domain];
        });

        $lines = [];
        $paidCost = 0.0;
        $paidCount = 0;
        $freeCount = 0;
        $deferred = 0;

        foreach ($candidates as $c) {
            /** @var Directory $directory */
            $directory = $c['directory'];
            $cost = $directory->cost_amount !== null ? (float) $directory->cost_amount : 0.0;
            $isPaid = $cost > 0.0;

            if ($isPaid) {
                if ($paidCost + $cost > $paidBudget) {
                    $deferred++;

                    continue; // over the batch budget — defer to next month
                }
                $paidCost += $cost;
                $paidCount++;
            } else {
                $freeCount++;
            }

            /** @var CitationStatus $status */
            $status = $c['status'];
            $lines[] = new WorkOrderLine(
                statusId: (string) $status->id,
                directoryName: (string) $directory->name,
                domain: (string) $directory->domain,
                action: $status->state,
                recommendation: $c['recommendation'],
                seoValue: $c['value'],
                cost: $isPaid ? $cost : null,
                submissionMethod: $directory->submission_method?->value,
                submissionUrl: $directory->submission_url,
                requiresClientAction: (bool) $directory->requires_client_action,
                turnaroundDays: $directory->avg_turnaround_days,
                mismatchFields: $status->state === CitationState::NeedsFix ? $status->mismatch_fields : null,
            );
        }

        return new WorkOrder(
            locationId: (string) $location->id,
            nap: $nap,
            lines: $lines,
            summary: [
                'total' => count($lines),
                'free' => $freeCount,
                'paid' => $paidCount,
                'paid_cost' => round($paidCost, 2),
                'deferred_over_budget' => $deferred,
            ],
            generatedAt: Carbon::now(),
        );
    }

    /** must_have (0) before recommended (1) before worth_paying (2). */
    private function priority(DirectoryRecommendation $recommendation): int
    {
        return match ($recommendation) {
            DirectoryRecommendation::MustHave => 0,
            DirectoryRecommendation::Recommended => 1,
            DirectoryRecommendation::WorthPaying => 2,
            default => 3,
        };
    }

    /** @return array<string, mixed> */
    private function napSnapshot(LocationNapProfile $profile): array
    {
        return [
            'business_name' => $profile->business_name,
            'address_1' => $profile->address_1,
            'address_2' => $profile->address_2,
            'city' => $profile->city,
            'state' => $profile->state,
            'postal' => $profile->postal,
            'phone_primary' => $profile->phone_primary,
            'phone_secondary' => $profile->phone_secondary,
            'website_url' => $profile->website_url,
            'categories' => $profile->categories,
            'description_short' => $profile->description_short,
            'verification_email' => $profile->verification_email,
        ];
    }
}
