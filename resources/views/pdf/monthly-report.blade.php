{{-- White-labeled §7c monthly report — dompdf print view. Table-based layout, inline styles, no
     external assets/web fonts so it renders identically headless on the queue. Honest framing:
     observed month-over-month movement only, never a guaranteed / attributed / ROI claim. --}}
@php
    $primary = $branding['primary'] ?? '#0B2545';
    $accent = $branding['accent'] ?? '#5BC0EB';
    $positions = $report['positions'];
    $summary = $positions['summary'];
    $search = $report['search'];
    $fmt = fn (int $n) => number_format($n);
    $delta = function (int $n): string {
        if ($n > 0) return '+'.number_format($n);
        if ($n < 0) return number_format($n);
        return '±0';
    };
    $tables = [
        ['heading' => 'Top climbers', 'rows' => $positions['improved']],
        ['heading' => 'Newly ranking', 'rows' => $positions['new']],
        ['heading' => 'Reached page one', 'rows' => $positions['page1']],
    ];
    $queries = $report['queries'] ?? [];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1f2933; font-size: 12px; }
        .wrap { padding: 32px 40px; }
        .bar { height: 6px; background: {{ $primary }}; }
        .brand { font-size: 22px; font-weight: bold; color: {{ $primary }}; margin: 20px 0 2px; }
        .sub { color: #6b7280; font-size: 12px; margin: 0 0 20px; }
        h2 { font-size: 14px; color: {{ $primary }}; margin: 24px 0 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .cards { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .cards td { width: 25%; padding: 10px; background: #f9fafb; border: 3px solid #ffffff; text-align: center; }
        .stat { font-size: 24px; font-weight: bold; color: {{ $primary }}; }
        .stat.muted { color: #374151; }
        .label { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .rows { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .rows th { text-align: left; font-size: 10px; color: #9ca3af; text-transform: uppercase; padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .rows td { padding: 6px; border-bottom: 1px solid #f3f4f6; font-size: 12px; }
        .rows td.num { text-align: right; color: #6b7280; white-space: nowrap; }
        .badge { color: #067647; font-weight: bold; }
        .note { margin-top: 24px; color: #9ca3af; font-size: 10px; line-height: 1.5; }
        .empty { color: #9ca3af; font-size: 12px; font-style: italic; }
    </style>
</head>
<body>
    <div class="bar"></div>
    <div class="wrap">
        <div class="brand">{{ $branding['name'] }}</div>
        <p class="sub">{{ $siteName }} &middot; Performance report &middot; {{ $report['month_label'] }}</p>

        <h2>Search ranking movement</h2>
        <p class="sub" style="margin-bottom:8px;">
            How your {{ $fmt($summary['tracked']) }} tracked
            {{ \Illuminate\Support\Str::plural('keyword', $summary['tracked']) }} moved this month versus last.
        </p>
        <table class="cards">
            <tr>
                <td><div class="stat">{{ $fmt($summary['improved']) }}</div><div class="label">Climbed</div></td>
                <td><div class="stat">{{ $fmt($summary['new']) }}</div><div class="label">Newly ranking</div></td>
                <td><div class="stat">{{ $fmt($summary['page1']) }}</div><div class="label">Reached page one</div></td>
                <td><div class="stat muted">{{ $fmt($summary['declined']) }}</div><div class="label">Slipped</div></td>
            </tr>
        </table>

        <h2>Search visibility</h2>
        @if ($search['connected'])
            <table class="cards">
                <tr>
                    <td>
                        <div class="stat">{{ $fmt($search['this']['impressions']) }}</div>
                        <div class="label">Impressions ({{ $delta($search['delta']['impressions']) }})</div>
                    </td>
                    <td>
                        <div class="stat">{{ $fmt($search['this']['clicks']) }}</div>
                        <div class="label">Clicks ({{ $delta($search['delta']['clicks']) }})</div>
                    </td>
                    <td style="background:#ffffff;border-color:#ffffff;"></td>
                    <td style="background:#ffffff;border-color:#ffffff;"></td>
                </tr>
            </table>
        @else
            <p class="empty">Search Console data is still collecting — this section fills in once Google has reported a full month.</p>
        @endif

        @if (count($queries) > 0)
            <h2>Top search queries ({{ count($queries) }})</h2>
            <p class="sub" style="margin-bottom:4px;">The searches that showed your site this month (Google Search Console) — the complete long tail, including local variants.</p>
            <table class="rows">
                <tr><th>Query</th><th style="text-align:right;">Impr.</th><th style="text-align:right;">Clicks</th><th style="text-align:right;">Avg pos.</th></tr>
                @foreach (array_slice($queries, 0, 25) as $qr)
                    <tr>
                        <td>{{ $qr['query'] }}</td>
                        <td class="num">{{ $fmt($qr['impressions']) }}</td>
                        <td class="num" style="color:#111827;font-weight:bold;">{{ $fmt($qr['clicks']) }}</td>
                        <td class="num">#{{ $qr['position'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @foreach ($tables as $table)
            @if (count($table['rows']) > 0)
                <h2>{{ $table['heading'] }} ({{ count($table['rows']) }})</h2>
                <table class="rows">
                    <tr><th>Keyword</th><th style="text-align:right;">Was</th><th style="text-align:right;">Now</th><th style="text-align:right;">Change</th></tr>
                    @foreach (array_slice($table['rows'], 0, 25) as $row)
                        <tr>
                            <td>{{ $row['query'] }}</td>
                            <td class="num">{{ $row['from'] !== null ? '#'.$row['from'] : '—' }}</td>
                            <td class="num" style="color:#111827;font-weight:bold;">{{ $row['to'] !== null ? '#'.$row['to'] : '—' }}</td>
                            <td class="num">@if ($row['delta'] !== null && $row['delta'] > 0)<span class="badge">+{{ $row['delta'] }}</span>@else—@endif</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach

        <p class="note">
            Rankings and search visibility are observed month over month. Positions can move for many
            reasons; this report shows what changed, not a forecast or guarantee of future results.
        </p>
    </div>
</body>
</html>
