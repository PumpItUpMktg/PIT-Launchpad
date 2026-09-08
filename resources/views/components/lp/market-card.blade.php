@props(['card'])
{{-- The ONE market card (standing rule 8) — one GBP-anchored Location (UI "Market"), rendered on the
     Markets wall from App\Operate\MarketCard::toArray(). An AGGREGATE card (built/total tier counts, a
     ranking DISTRIBUTION, a size-tier grid, a proof row), deliberately distinct from the per-page
     <x-lp.content-card>, but sharing its vocabulary: <x-lp.chip>, the token palette, and the absent-state
     rule — every empty value renders as its OWN state ("Not tracked" / "Never checked"), never a 0 or a
     blank. A HELD market is dimmed and drops the metric row + ranking (unpublished pages have nothing to
     report) but KEEPS its tier counts and proof. --}}
@php
    $c = $card;
    $held = ! empty($c['held']);
    $metric = fn (?int $v): string => $v !== null ? number_format($v) : 'Not tracked';
    $delta = function (?int $d): string {
        if ($d === null || $d === 0) {
            return '';
        }

        return $d > 0 ? "▲".number_format($d) : "▼".number_format(abs($d));
    };
@endphp
@once
    <style>
        .lp-mc { display:flex; flex-direction:column; gap:12px; padding:16px 18px; border:1px solid var(--line,#e5e7eb); border-radius:14px; background:var(--card,#fff); }
        .lp-mc.is-held { opacity:.72; }
        .lp-mc-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
        .lp-mc-name { font-size:16px; font-weight:800; color:var(--ink,#0f172a); }
        .lp-mc-geo { font-size:12px; color:var(--ink-soft,#64748b); margin-top:2px; }
        .lp-mc-page { font-size:12px; margin-top:4px; }
        .lp-mc-page a { color:var(--teal-deep,#2B5C7A); text-decoration:none; font-weight:600; }
        .lp-mc-page a:hover { text-decoration:underline; }
        .lp-mc-page .muted { color:var(--ink-soft,#94a3b8); }
        .lp-mc-badges { display:flex; gap:6px; flex-wrap:wrap; align-items:flex-start; }
        .lp-mc-sub { font-size:12.5px; color:var(--ink,#334155); font-weight:600; }
        .lp-mc-sub .muted { color:var(--ink-soft,#94a3b8); font-weight:500; }
        .lp-mc-metrics { display:flex; gap:22px; flex-wrap:wrap; align-items:baseline; }
        .lp-mc-metric .l { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft,#94a3b8); }
        .lp-mc-metric .v { font-size:17px; font-weight:800; color:var(--ink,#0f172a); font-variant-numeric:tabular-nums; }
        .lp-mc-metric .v.nt { font-size:12px; font-weight:600; color:var(--ink-soft,#94a3b8); text-transform:none; letter-spacing:0; }
        .lp-mc-metric .d { font-size:11px; font-weight:700; margin-left:4px; }
        .lp-mc-metric .d.up { color:#2E7D6B; } .lp-mc-metric .d.down { color:#B5341A; }
        .lp-mc-rank { font-size:12.5px; color:var(--ink,#334155); }
        .lp-mc-rank .muted { color:var(--ink-soft,#94a3b8); }
        .lp-mc-tiers { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }
        .lp-mc-tier { border:1px solid var(--line,#e5e7eb); border-radius:9px; padding:8px 10px; }
        .lp-mc-tier .t { font-size:9.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft,#94a3b8); font-weight:700; }
        .lp-mc-tier .c { font-size:13px; font-weight:800; color:var(--ink,#0f172a); font-variant-numeric:tabular-nums; margin-top:2px; }
        .lp-mc-bar { height:4px; border-radius:2px; background:var(--paper,#eef1f4); margin-top:6px; overflow:hidden; }
        .lp-mc-bar > i { display:block; height:100%; background:#2E7D6B; }
        .lp-mc-proof { display:flex; gap:16px; flex-wrap:wrap; align-items:center; font-size:12px; color:var(--ink-soft,#64748b); border-top:1px solid var(--line,#eef1f4); padding-top:10px; }
        .lp-mc-proof b { color:var(--ink,#0f172a); font-weight:800; }
        .lp-mc-fresh { display:flex; gap:14px; flex-wrap:wrap; font-size:10.5px; color:var(--ink-soft,#94a3b8); }
        .lp-mc-fresh .late { color:#B5731A; } .lp-mc-fresh .stale { color:#B5341A; }
        @media (prefers-color-scheme: dark) { .lp-mc { background:#151b24; } .lp-mc-name, .lp-mc-metric .v, .lp-mc-tier .c, .lp-mc-proof b { color:#f1f5f9; } }
    </style>
@endonce
<div class="lp-mc {{ $held ? 'is-held' : '' }}" wire:key="mc-{{ $c['id'] }}">
    {{-- Header: name · county/state · market page + index verdict · GBP link --}}
    <div class="lp-mc-head">
        <div>
            <div class="lp-mc-name">{{ $c['name'] }}</div>
            <div class="lp-mc-geo">{{ collect([$c['county'], $c['state']])->filter()->implode(', ') ?: 'Location not geocoded' }}</div>
            <div class="lp-mc-page">
                @if ($c['market_page_url'])
                    <a href="{{ $c['market_page_url'] }}" target="_blank" rel="noopener">Market page ↗</a>
                    @if ($c['market_page_position'] !== null) · #{{ $c['market_page_position'] }} @else · <span class="muted">position not tracked</span> @endif
                @else
                    <span class="muted">Market page not built</span>
                @endif
            </div>
        </div>
        <div class="lp-mc-badges">
            @if ($held)
                <x-lp.chip tone="warn">Held — seasoning@if ($c['drafted_pages'] > 0) · {{ $c['drafted_pages'] }} drafted @endif</x-lp.chip>
            @endif
            <x-lp.chip :tone="$c['market_page_index_state'] === 'indexed' ? 'good' : 'neutral'">
                {{ ['indexed' => 'Indexed', 'not_indexed' => 'Not indexed', 'unchecked' => 'Not yet checked'][$c['market_page_index_state']] ?? 'Not yet checked' }}
            </x-lp.chip>
            @if ($c['gbp_url'])
                <a href="{{ $c['gbp_url'] }}" target="_blank" rel="noopener"><x-lp.chip tone="info">GBP ↗</x-lp.chip></a>
            @endif
            @if (! empty($c['county_mismatch']))
                <x-lp.chip tone="bad" title="{{ $c['county_mismatch'] }}">County mismatch</x-lp.chip>
            @endif
        </div>
    </div>

    {{-- Deployment sub-line --}}
    <div class="lp-mc-sub">
        {{ number_format($c['towns_with_pages']) }} of {{ number_format($c['towns_total']) }} towns have pages
        @if ($c['to_deploy'] > 0)<span class="muted"> · {{ number_format($c['to_deploy']) }} to deploy</span>@endif
    </div>

    {{-- Metric row (3 tiles) — omitted for a held market (nothing published to report) --}}
    @unless ($held)
        <div class="lp-mc-metrics">
            <div class="lp-mc-metric">
                <div class="l">Impressions</div>
                <div class="v {{ $c['impressions'] === null ? 'nt' : '' }}">{{ $metric($c['impressions']) }}@if ($delta($c['impressions_delta']))<span class="d {{ $c['impressions_delta'] > 0 ? 'up' : 'down' }}">{{ $delta($c['impressions_delta']) }}</span>@endif</div>
            </div>
            <div class="lp-mc-metric">
                <div class="l">Clicks</div>
                <div class="v {{ $c['clicks'] === null ? 'nt' : '' }}">{{ $metric($c['clicks']) }}@if ($delta($c['clicks_delta']))<span class="d {{ $c['clicks_delta'] > 0 ? 'up' : 'down' }}">{{ $delta($c['clicks_delta']) }}</span>@endif</div>
            </div>
            <div class="lp-mc-metric">
                <div class="l">Sessions</div>
                <div class="v {{ $c['sessions'] === null ? 'nt' : '' }}">{{ $metric($c['sessions']) }}</div>
            </div>
        </div>

        {{-- Ranking distribution — never an average; NotTracked when no page holds a position --}}
        <div class="lp-mc-rank">
            @if ($c['ranking_state'] === 'not_tracked')
                <span class="muted">Rankings: Not tracked</span>
            @else
                Rankings: <b>{{ $c['rank_top3'] }}</b> top 3 · <b>{{ $c['rank_page_one'] }}</b> page one · <b>{{ $c['rank_beyond'] }}</b> beyond
            @endif
        </div>
    @endunless

    {{-- Size-tier deployment grid (always shown, incl. held) — four cells, built against total --}}
    <div class="lp-mc-tiers">
        @foreach ($c['size_tiers'] as $t)
            <div class="lp-mc-tier">
                <div class="t">{{ $t['label'] }}</div>
                <div class="c">{{ $t['built'] }} / {{ $t['served'] }}</div>
                <div class="lp-mc-bar"><i style="width:{{ $t['served'] > 0 ? min(100, round($t['built'] / $t['served'] * 100)) : 0 }}%"></i></div>
            </div>
        @endforeach
    </div>

    {{-- Proof row --}}
    <div class="lp-mc-proof">
        <span>Reviews @if ($c['reviews_count'] !== null)<b>{{ number_format($c['reviews_count']) }}</b>@if ($c['reviews_avg'] !== null) · {{ $c['reviews_avg'] }}★@endif @else<b>Not tracked</b>@endif</span>
        <span>Citations @if ($c['citations_live'] !== null)<b>{{ number_format($c['citations_live']) }}</b>@else<b>Not tracked</b>@endif</span>
        <span>Jobs <b>Not tracked</b></span>
        @if ($c['proof_freshness'])<span class="muted">{{ $c['proof_freshness']['line'] }}</span>@endif
    </div>

    {{-- Per-source freshness — GSC and GA4 each with their OWN cadence, never averaged (omitted when held) --}}
    @unless ($held)
        <div class="lp-mc-fresh">
            @if ($c['gsc_freshness'])<span class="{{ $c['gsc_freshness']['severity'] }}">GSC (daily) — {{ $c['gsc_freshness']['line'] }}</span>@endif
            @if ($c['ga4_freshness'])<span class="{{ $c['ga4_freshness']['severity'] }}">GA4 (weekly) — {{ $c['ga4_freshness']['line'] }}</span>@endif
        </div>
    @endunless
</div>
