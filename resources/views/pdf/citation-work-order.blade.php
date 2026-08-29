@php
    /** @var \App\Citations\WorkOrder\WorkOrder $order */
    $nap = $order->nap;
    $addr = trim(($nap['address_1'] ?? '').' '.($nap['address_2'] ?? ''));
    $cityLine = trim(($nap['city'] ?? '').', '.($nap['state'] ?? '').' '.($nap['postal'] ?? ''), ' ,');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1a1a1a; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #666; font-size: 10px; }
        .nap { border: 1px solid #ccc; padding: 10px 12px; margin: 12px 0 16px; background: #f7f9fc; }
        .nap h2 { font-size: 12px; margin: 0 0 6px; text-transform: uppercase; letter-spacing: .04em; color: #0B2545; }
        .nap div { margin: 1px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { text-align: left; padding: 6px 5px; border-bottom: 1px solid #e2e2e2; vertical-align: top; }
        th { background: #0B2545; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .03em; }
        .action { font-weight: bold; white-space: nowrap; }
        .fix { color: #b23b00; font-size: 10px; }
        .summary { margin-top: 14px; font-size: 10px; color: #444; }
        .badge { font-size: 9px; padding: 1px 5px; border-radius: 3px; background: #eef; color: #224; }
    </style>
</head>
<body>
    <h1>Citation Work Order</h1>
    <div class="muted">Generated {{ $order->generatedAt->format('F j, Y') }} &middot; {{ $order->summary['total'] }} directories
        ({{ $order->summary['free'] }} free, {{ $order->summary['paid'] }} paid &middot; ${{ number_format($order->summary['paid_cost'], 2) }})</div>

    <div class="nap">
        <h2>Submit this exactly — do not alter</h2>
        <div><strong>{{ $nap['business_name'] ?? '' }}</strong></div>
        <div>{{ $addr }}</div>
        <div>{{ $cityLine }}</div>
        <div>Phone: {{ $nap['phone_primary'] ?? '' }}</div>
        @if(!empty($nap['website_url']))<div>Web: {{ $nap['website_url'] }}</div>@endif
        @if(!empty($nap['verification_email']))<div>Verification email: {{ $nap['verification_email'] }}</div>@endif
    </div>

    @if($order->isEmpty())
        <p class="muted">No actionable citation gaps for this location.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Action</th><th>Directory</th><th>Where to submit</th><th>Method</th><th>Value</th><th>Cost</th><th>Turnaround</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->lines as $line)
                    <tr>
                        <td class="action">{{ $line->actionLabel() }}</td>
                        <td>
                            {{ $line->directoryName }}<br>
                            <span class="muted">{{ $line->domain }}</span>
                            @if($line->requiresClientAction)<br><span class="badge">needs client action</span>@endif
                            @if($line->mismatchFields)<br><span class="fix">Fix: {{ implode(', ', array_keys($line->mismatchFields)) }}</span>@endif
                        </td>
                        <td>{{ $line->submissionUrl ?? '—' }}</td>
                        <td>{{ $line->submissionMethod ?? '—' }}</td>
                        <td>{{ $line->seoValue }}</td>
                        <td>{{ $line->cost !== null ? '$'.number_format($line->cost, 2) : 'Free' }}</td>
                        <td>{{ $line->turnaroundDays !== null ? $line->turnaroundDays.' d' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($order->summary['deferred_over_budget'] > 0)
        <div class="summary">{{ $order->summary['deferred_over_budget'] }} paid directories deferred to the next batch (over budget).</div>
    @endif
</body>
</html>
