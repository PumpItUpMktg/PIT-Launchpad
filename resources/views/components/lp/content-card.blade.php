@props(['row', 'actions' => null])
{{-- The ONE content-row card (standing rule): a published/queued content row rendered the same way on
     every board — Live, the pipeline boards, the dashboard, the lobby. Takes a normalized `$row`
     (see App\Operate\LiveBoard::card) and an optional `actions` slot for the surface's buttons. Flags
     render through the shared <x-lp.chip> badge vocabulary — no per-board pill. --}}
@once
    <style>
        .lp-cc { display:grid; grid-template-columns:1fr auto; gap:6px 16px; padding:14px 16px; border:1px solid var(--line,#e5e7eb); border-radius:12px; background:#fff; }
        .lp-cc .cc-tt { font-size:15px; font-weight:700; color:#0f172a; text-decoration:none; }
        .lp-cc .cc-tt:hover { color:#b45309; }
        .lp-cc-type { font-size:10px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#64748b; background:#eef1f4; border-radius:6px; padding:2px 7px; margin-right:8px; }
        .lp-cc-flags { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
        .lp-cc-metrics { display:flex; gap:18px; align-items:baseline; font-size:12.5px; color:#64748b; margin-top:8px; flex-wrap:wrap; }
        .lp-cc-metrics b { font-size:15px; color:#0f172a; font-weight:800; }
        .lp-cc-metrics .up { color:#2E7D6B; } .lp-cc-metrics .down { color:#B5341A; }
        .lp-cc-actions { display:flex; gap:6px; align-items:flex-start; }
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
        </div>
        <div class="lp-cc-flags">
            {{-- Three-state index verdict when the row carries it (Live board): Indexed / Not indexed /
                 Not yet checked. Falls back to the binary for any board that only sets `indexed`. --}}
            <x-lp.chip :tone="$row['index_tone'] ?? (! empty($row['indexed']) ? 'good' : 'neutral')" :label="$row['index_label'] ?? (! empty($row['indexed']) ? 'Indexed' : 'Not indexed')" />
            @if (! empty($row['in_bing'])) <x-lp.chip tone="info" label="Bing" /> @endif
            @if (! empty($row['page_one'])) <x-lp.chip tone="good" label="Page one" /> @endif
            @if (! empty($row['problem'])) <x-lp.chip tone="warn" :label="$row['problem']" /> @endif
        </div>
        @if (! empty($row['pending']))
            <div class="lp-cc-metrics"><span>Refreshing… tracking updates shortly</span></div>
        @else
            <div class="lp-cc-metrics">
                <span>Rank <b>{{ $row['rank'] ?? '—' }}</b>@if (($row['delta'] ?? 0) != 0)<span class="{{ $row['delta'] > 0 ? 'up' : 'down' }}"> {{ $row['delta'] > 0 ? '▲' : '▼' }}{{ abs($row['delta']) }}</span>@endif</span>
                <span>Impressions <b>{{ isset($row['impressions']) && $row['impressions'] !== null ? number_format($row['impressions']) : '—' }}</b></span>
                <span>Clicks <b>{{ isset($row['clicks']) && $row['clicks'] !== null ? number_format($row['clicks']) : '—' }}</b></span>
                <span>Sessions <b>{{ isset($row['sessions']) && $row['sessions'] !== null ? number_format($row['sessions']) : '—' }}</b></span>
                @if (! empty($row['keyword'])) <span>Target <b style="font-size:12.5px">{{ $row['keyword'] }}</b></span> @endif
            </div>
        @endif
    </div>
    @if ($actions !== null)
        <div class="lp-cc-actions">{{ $actions }}</div>
    @endif
</div>
