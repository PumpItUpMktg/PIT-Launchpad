<?php

namespace App\Console\Commands;

use App\Citations\WorkOrder\WorkOrderBuilder;
use App\Citations\WorkOrder\WorkOrderCsv;
use App\Citations\WorkOrder\WorkOrderPdf;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Support\CurrentSite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Generate a VA citation work order for a location (§ Citations, PR6): the prioritized, budget-capped batch of
 * gaps rendered to PDF and/or CSV under storage/app/citation-work-orders. Honors the tenant paid budget
 * (overridable with --budget).
 */
class CitationWorkOrderCommand extends Command
{
    protected $signature = 'launchpad:citation-work-order {--location= : Location id (required)} {--format=both : pdf|csv|both} {--budget= : Paid budget override for this batch}';

    protected $description = 'Generate a prioritized citation work order (PDF/CSV) for a location.';

    public function handle(WorkOrderBuilder $builder, WorkOrderPdf $pdf, WorkOrderCsv $csv): int
    {
        $locationId = $this->option('location');
        if (! is_string($locationId) || $locationId === '') {
            $this->error('Pass --location=<id>.');

            return self::FAILURE;
        }

        $location = Location::query()->withoutGlobalScope(SiteScope::class)->find($locationId);
        if ($location === null) {
            $this->error("No location {$locationId}.");

            return self::FAILURE;
        }
        CurrentSite::set((string) $location->site_id);

        $budgetOption = $this->option('budget');
        $budget = is_string($budgetOption) && $budgetOption !== '' ? (float) $budgetOption : null;

        $order = $builder->build($location, $budget);
        $format = (string) $this->option('format');
        $dir = 'citation-work-orders';

        if (in_array($format, ['pdf', 'both'], true)) {
            $name = $dir.'/'.$pdf->filename($order);
            Storage::put($name, $pdf->render($order)->output());
            $this->info("PDF: {$name}");
        }
        if (in_array($format, ['csv', 'both'], true)) {
            $name = $dir.'/'.str_replace('.pdf', '.csv', $pdf->filename($order));
            Storage::put($name, $csv->render($order));
            $this->info("CSV: {$name}");
        }

        $this->info("Work order: {$order->summary['total']} directories "
            ."({$order->summary['free']} free, {$order->summary['paid']} paid, \${$order->summary['paid_cost']}); "
            ."{$order->summary['deferred_over_budget']} deferred over budget.");

        return self::SUCCESS;
    }
}
