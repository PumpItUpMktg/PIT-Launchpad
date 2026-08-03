<?php

namespace App\Client;

use App\Models\Site;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Renders the §7c monthly keyword-improvement report to a white-labeled PDF — the downloadable and
 * emailable face of {@see MonthlyKeywordReport}, sharing its exact view-model so the document never
 * shows numbers the in-panel page doesn't. The PDF is the client's Account brand throughout (name +
 * primary color); Launchpad is invisible. Honest framing carries over from the data layer: observed
 * movement only, no ROI / causal claims. dompdf renders a self-contained, table-based print view
 * (inline styles, no external assets or web fonts) so it renders identically headless on the queue.
 */
class MonthlyReportPdf
{
    public function __construct(
        private readonly MonthlyKeywordReport $report,
    ) {}

    /**
     * The rendered PDF for a site's month (defaults to the current month). Callers stream it as a
     * download or attach it to the monthly mail.
     */
    public function for(Site $site, ?Carbon $month = null): PDF
    {
        $data = $this->report->for($site, $month);
        $branding = $site->account?->branding() ?? [
            'name' => 'Performance', 'logo_url' => null, 'primary' => '#0B2545', 'accent' => '#5BC0EB',
        ];

        return PdfFacade::loadView('pdf.monthly-report', [
            'report' => $data,
            'branding' => $branding,
            'siteName' => (string) ($site->brand_name ?: $branding['name']),
        ])->setPaper('letter');
    }

    /** A stable, brand + month named filename ("acme-plumbing-2026-06.pdf"). */
    public function filename(Site $site, ?Carbon $month = null): string
    {
        $month = ($month ?? Carbon::now())->copy()->startOfMonth();
        $brand = $site->brand_name ?: ($site->account?->branding()['name'] ?? 'performance');
        $slug = Str::slug((string) $brand) ?: 'report';

        return sprintf('%s-%s.pdf', $slug, $month->format('Y-m'));
    }
}
