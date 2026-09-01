<?php

namespace App\Mail;

use App\Client\MonthlyKeywordReport;
use App\Client\MonthlyReportPdf;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The §7c client's monthly performance email — white-labeled to the Account brand, with the full
 * report attached as a PDF. Queued (payload is just the Site + a 'Y-m' month key; the report and PDF
 * render on the worker at send time, so the job stays tiny). Honest framing carries through from the
 * data layer — the body reports observed movement, never a guaranteed / attributed result.
 */
class MonthlyReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Site $site,
        public string $monthKey,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('%s — Your %s performance report', $this->branding()['name'], $this->month()->format('F Y')),
        );
    }

    public function content(): Content
    {
        $report = app(MonthlyKeywordReport::class)->for($this->site, $this->month());

        return new Content(markdown: 'mail.monthly-report', with: [
            'brand' => $this->branding()['name'],
            'siteName' => (string) ($this->site->brand_name ?: $this->branding()['name']),
            'monthLabel' => $report['month_label'],
            'summary' => $report['positions']['summary'],
            'search' => $report['search'],
        ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(MonthlyReportPdf::class);

        return [
            Attachment::fromData(fn (): string => $pdf->for($this->site, $this->month())->output(), $pdf->filename($this->site, $this->month()))
                ->withMime('application/pdf'),
        ];
    }

    private function month(): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $this->monthKey)->startOfMonth();
    }

    /**
     * @return array{name: string, logo_url: string|null, primary: string, accent: string}
     */
    private function branding(): array
    {
        return $this->site->account?->branding() ?? [
            'name' => 'Performance', 'logo_url' => null, 'primary' => '#0B2545', 'accent' => '#5BC0EB',
        ];
    }
}
