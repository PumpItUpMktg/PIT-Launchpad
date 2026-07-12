{{-- Totals hero: covered / selected + the tier distribution bar. Expects $totals, $tierTotals,
     $overlap, $tierMeta from the including blade. --}}
<div class="lp-card">
    <div class="lp-hero-row">
        <div class="lp-nums">
            <div>
                <div class="lp-num">{{ $totals['covered'] }}</div>
                <div class="lp-num-lbl">towns covered</div>
            </div>
            <div>
                <div class="lp-num alt">{{ $totals['selected'] }}</div>
                <div class="lp-num-lbl">selected for pages</div>
            </div>
        </div>
        <span class="lp-badge {{ $overlap === 0 ? 'ok' : 'warn' }}">{{ $overlap === 0 ? 'no overlap' : $overlap.' overlapping' }}</span>
    </div>

    @if ($totals['covered'] > 0)
        <div class="lp-bar">
            @foreach ($tierMeta as $key => $meta)
                @php $n = $tierTotals[$key] ?? 0; $pct = $totals['covered'] > 0 ? ($n / $totals['covered']) * 100 : 0; @endphp
                @if ($n > 0)
                    <span style="width: {{ $pct }}%; background: {{ $meta['color'] }}" title="{{ $meta['label'] }}: {{ $n }}"></span>
                @endif
            @endforeach
        </div>
        <div class="lp-legend">
            @foreach ($tierMeta as $key => $meta)
                <span><span class="lp-sw" style="background: {{ $meta['color'] }}"></span>{{ $meta['label'] }} {{ $tierTotals[$key] ?? 0 }}</span>
            @endforeach
        </div>
    @endif
</div>
