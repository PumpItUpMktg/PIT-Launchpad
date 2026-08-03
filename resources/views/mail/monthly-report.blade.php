@php
    $climbed = $summary['improved'] + $summary['new'];
@endphp
<x-mail::message>
# Your {{ $monthLabel }} report

Hi — here's how **{{ $siteName }}** performed in search this month.

<x-mail::panel>
**{{ number_format($summary['improved']) }}** keywords climbed &middot;
**{{ number_format($summary['new']) }}** newly ranking &middot;
**{{ number_format($summary['page1']) }}** reached page one
@if ($search['connected'])
<br>**{{ number_format($search['this']['impressions']) }}** search impressions &middot;
**{{ number_format($search['this']['clicks']) }}** clicks
@endif
</x-mail::panel>

The full breakdown — every climber, newly ranking keyword, and page-one gain — is attached as a PDF.

These are observed month-over-month changes in your search rankings and visibility. Positions can move for many reasons; this report shows what changed, not a forecast or guarantee of future results.

Thanks,<br>
{{ $brand }}
</x-mail::message>
