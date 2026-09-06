@props(['row', 'actions' => null])
{{-- The ONE content-row card (standing rule 8): a published/queued content row rendered the same way on
     every board — Live, the Pages boards (Core / Service / Town), the dashboard, the lobby. Takes a
     normalized $row (App\Operate\ContentCard::toArray) + an optional `actions` slot for the surface's
     buttons. Flags render through the shared <x-lp.chip> vocabulary. The rich blocks (days-live, IndexNow,
     local-pack, sparkline, GSC query terms) render ONLY when the row carries them — a lean board (Live)
     omits them, a rich board (Pages) passes them; one component, no divergence. --}}
@php
    // Position sparkline (ranks inverted so #1 sits at the top of the box). Oldest→newest, max 24 points.
    $spark = '';
    $points = collect($row['series'] ?? [])->filter(fn ($p) => ($p['rank'] ?? null) !== null)->sortBy('captured_at')->values()->take(-24);
    if ($points->count() >= 2) {
        $min = max(1, (int) $points->pluck('rank')->min());
        $max = max($min + 1, (int) $points->pluck('rank')->max());
        $spark = $points->map(fn ($p, $i) => round($i / ($points->count() - 1) * 200, 1).','.round(4 + (($p['rank'] - $min) / ($max - $min)) * 22, 1))->implode(' ');
    }
    $metricCell = fn ($value, $pending) => $value !== null ? number_format((int) $value) : ($pending ?? '—');
@endphp
@once
    <style>
        .lp-cc { display:grid; grid-template-columns:1fr auto; gap:6px 16px; padding:14px 16px; border:1px solid var(--line,#e5e7eb); border-radius:12px; background:#fff; }
        .lp-cc .cc-tt { font-size:15px; font-weight:700; color:#0f172a; text-decoration:none; }
        .lp-cc .cc-tt:hover { color:#b45309; }
        .lp-cc-type { font-size:10px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#64748b; background:#eef1f4; border-radius:6px; padding:2px 7px; margin-right:8px; }
        .lp-cc-live { font-size:10px; font-weight:700; color:#64748b; margin-left:8px; }
        .lp-cc-flags { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
        .lp-cc-metrics { display:flex; gap:18px; align-items:baseline; font-size:12.5px; color:#64748b; margin-top:8px; flex-wrap:wrap; }
        .lp-cc-metrics b { font-size:15px; color:#0f172a; font-weight:800; }
        .lp-cc-metrics .up { color:#2E7D6B; } .lp-cc-metrics .down { color:#B5341A; }
        .lp-cc-spark { display:flex; align-items:center; gap:8px; margin-top:8px; }
        .lp-cc-spark svg { width:120px; height:22px; }
        .lp-cc-spark .cap { font-size:11px; color:#94a3b8; }
        .lp-cc-queries { margin-top:8px; display:flex; flex-wrap:wrap; gap:5px; }
        .lp-cc-queries .qh { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; width:100%; }
        .lp-cc-q { font-size:11px; color:#334155; background:rgba(148,163,184,.14); border:1px solid rgba(148,163,184,.28); border-radius:99px; padding:2px 9px; }
        .lp-cc-q span { color:#94a3b8; }
        .lp-cc-actions { display:flex; gap:6px; align-items:flex-start; flex-wrap:wrap; }
        @media (prefers-color-scheme: dark) {
            .lp-cc { background:#151b24; } .lp-cc .cc-tt { color:#f1f5f9; } .lp-cc-metrics b { color:#f1f5f9; }
        }
    </style>
@endonce
<div class="lp-cc" {{ $attributes }}>
    <div>
        <div>
            @isset($row['type_label'])<span class="lp-cc-type">{{ $row['type_label'] }}</span>@endisset
            <a class="cc-tt" href="{{ $row['url'] }}" target="_blank" rel="noopener">{{ $row['title'] }}</a>
            @if (! empty($row['locked'])) <x-lp.chip tone="neutral" label="🔒 Locked" /> @endif
            @isset($row['days_live'])@if ($row['days_live'] !== null)<span class="lp-cc-live">Live · {{ $row['days_live'] }}d</span>@endif @endisset
        </div>
        <div class="lp-cc-flags">
            {{-- Three-state index verdict (Indexed / Not indexed / Not yet checked) — always present, from the
                 durable page_index_states, so a PASS row can never render no chip. Coverage detail (if any)
                 rides the tooltip. --}}
            <x-lp.chip
                :tone="$row['index_tone'] ?? (! empty($row['indexed']) ? 'good' : 'neutral')"
                :label="$row['index_label'] ?? (! empty($row['indexed']) ? 'Indexed' : 'Not indexed')"
                :title="! empty($row['index_coverage_state']) ? 'Google URL Inspection: '.$row['index_coverage_state'] : null" />
            @if (! empty($row['in_bing']))
                <x-lp.chip tone="good" label="✓ In Bing" />
            @elseif (! empty($row['indexnow_at']))
                <x-lp.chip tone="info" label="↗ Submitted to Bing" title="Submitted via IndexNow on {{ $row['indexnow_at'] }} — a submission ack, not a confirmed index." />
            @endif
            @if (($row['local_rank'] ?? null) !== null)
                <x-lp.chip tone="info" label="Local pack #{{ $row['local_rank'] }}{{ ! empty($row['local_market']) ? ' · '.$row['local_market'] : '' }}" />
            @endif
            @if (! empty($row['page_one'])) <x-lp.chip tone="good" label="Page one" /> @endif
            @if (! empty($row['problem'])) <x-lp.chip tone="warn" :label="$row['problem']" /> @endif
        </div>
        @if (! empty($row['pending']))
            <div class="lp-cc-metrics"><span>Refreshing… tracking updates shortly</span></div>
        @else
            <div class="lp-cc-metrics">
                <span>Rank <b>{{ $row['rank'] ?? '—' }}</b>@if (($row['delta'] ?? 0) != 0)<span class="{{ $row['delta'] > 0 ? 'up' : 'down' }}"> {{ $row['delta'] > 0 ? '▲' : '▼' }}{{ abs($row['delta']) }}</span>@endif</span>
                <span>Impressions <b>{{ $metricCell($row['impressions'] ?? null, $row['gsc_pending'] ?? null) }}</b></span>
                <span>Clicks <b>{{ $metricCell($row['clicks'] ?? null, $row['gsc_pending'] ?? null) }}</b></span>
                <span>Sessions <b>{{ $metricCell($row['sessions'] ?? null, $row['traffic_pending'] ?? null) }}</b></span>
                @if (! empty($row['keyword'])) <span>Target <b style="font-size:12.5px">{{ $row['keyword'] }}</b></span> @endif
            </div>
            @if ($spark !== '')
                <div class="lp-cc-spark">
                    <svg viewBox="0 0 200 30" preserveAspectRatio="none" role="img" aria-label="position trend"><polyline points="{{ $spark }}" fill="none" stroke="#4f46e5" stroke-width="1.8"/></svg>
                    <span class="cap">position{{ ($row['refresh_count'] ?? 0) > 0 ? ' · '.$row['refresh_count'].' refresh'.($row['refresh_count'] === 1 ? '' : 'es') : '' }}</span>
                </div>
            @endif
            @if (($row['queries'] ?? []) !== [])
                <div class="lp-cc-queries">
                    <span class="qh">Found in search for</span>
                    @foreach (array_slice($row['queries'], 0, 6) as $qr)
                        <span class="lp-cc-q" title="{{ number_format($qr['impressions']) }} impressions · {{ number_format($qr['clicks']) }} clicks · avg #{{ $qr['position'] }}">{{ $qr['query'] }} <span>#{{ $qr['position'] }}</span></span>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
    @if ($actions !== null)
        <div class="lp-cc-actions">{{ $actions }}</div>
    @endif
</div>
