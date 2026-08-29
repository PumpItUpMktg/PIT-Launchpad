<?php

namespace App\Citations\WorkOrder;

use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Str;

/**
 * Renders a {@see WorkOrder} to a print-ready PDF (§ Citations, PR6) — the human-friendly VA handout: the
 * canonical NAP block at the top (copy this exactly), then each directory with its submission target and
 * instructions. Self-contained, table-based, inline styles only, so it renders identically headless.
 */
final class WorkOrderPdf
{
    public function render(WorkOrder $order): PDF
    {
        return PdfFacade::loadView('pdf.citation-work-order', ['order' => $order])->setPaper('letter');
    }

    public function filename(WorkOrder $order): string
    {
        $brand = (string) ($order->nap['business_name'] ?? 'citations');
        $slug = Str::slug($brand) ?: 'citations';

        return sprintf('%s-citation-work-order-%s.pdf', $slug, $order->generatedAt->format('Y-m-d'));
    }
}
