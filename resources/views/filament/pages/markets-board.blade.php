<x-lp.shell
    variant="table"
    eyebrow="Territory"
    title="Markets"
    lede="The tenant's targetable geo subjects — tier, coverage, demographics and the pages & keywords pinned to each. Place an advisory hold to defer a market; a hold is a reminder only and never affects publishing.">

    @php($board = $this->board)
    @php($summary = $board['summary'])
    @php($markets = $board['markets'])

    <style>
        .mk-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
        .mk-stat { background:var(--card); border:1px solid var(--line); border-radius:11px; padding:12px 16px; min-width:120px; }
        .mk-stat .n { font-family:'Spline Sans Mono',monospace; font-size:22px; font-weight:600; color:var(--teal-deep); }
        .mk-stat .n.warn { color:var(--amber); } .mk-stat .n.bad { color:#B5341A; }
        .mk-stat .l { font-size:11px; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
        .mk-table { width:100%; border-collapse:collapse; font-size:13px; background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        .mk-table th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft); font-weight:700; padding:11px 14px; border-bottom:1px solid var(--line); background:var(--paper); }
        .mk-table td { padding:12px 14px; border-bottom:1px solid var(--line); vertical-align:middle; }
        .mk-table tr:last-child td { border-bottom:0; }
        .mk-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
        .mk-name { font-weight:700; color:var(--ink); }
        .mk-region { color:var(--ink-soft); font-size:12px; }
        .mk-badges { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .mk-hold-note { font-size:11.5px; color:var(--ink-soft); margin-top:3px; }
        .mk-release { font-size:12px; font-weight:600; color:#B5341A; background:none; border:1px solid var(--line); border-radius:8px; padding:5px 11px; cursor:pointer; }
        .mk-release:hover { border-color:#B5341A; }
        .mk-dash { color:var(--ungrouped); }
    </style>

    @if ($this->siteId === null)
        <x-lp.empty title="No tenant selected" action="Go to Portfolio" :href="\App\Filament\Resources\SiteResource::getUrl('index')">
            Pick a working tenant from the topbar to see its markets.
        </x-lp.empty>
    @elseif (empty($markets))
        <x-lp.empty title="No markets yet" action="Open Setup" :href="\App\Filament\Pages\Onboarding::getUrl()">
            Markets are seeded from the tenant's service area — the page-selected towns project into Priority and Coverage markets. Run Setup for this tenant first.
        </x-lp.empty>
    @else
        <div class="mk-stats">
            <div class="mk-stat"><div class="n">{{ number_format($summary['total']) }}</div><div class="l">Markets</div></div>
            <div class="mk-stat"><div class="n">{{ number_format($summary['priority']) }}</div><div class="l">Priority</div></div>
            <div class="mk-stat"><div class="n">{{ number_format($summary['covered']) }}</div><div class="l">Covered</div></div>
            <div class="mk-stat"><div class="n {{ $summary['held'] ? 'warn' : '' }}">{{ number_format($summary['held']) }}</div><div class="l">On hold</div></div>
            <div class="mk-stat"><div class="n {{ $summary['overdue'] ? 'bad' : '' }}">{{ number_format($summary['overdue']) }}</div><div class="l">Overdue</div></div>
        </div>

        <table class="mk-table">
            <thead>
                <tr>
                    <th>Market</th>
                    <th>Status</th>
                    <th style="text-align:right">Population</th>
                    <th style="text-align:right">Pages</th>
                    <th style="text-align:right">Keywords</th>
                    <th>Hold</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($markets as $m)
                    <tr wire:key="mk-{{ $m['id'] }}">
                        <td>
                            <div class="mk-name">{{ $m['name'] }}</div>
                            <div class="mk-region">{{ $m['region'] ?? '—' }}@if ($m['neighborhoods'] > 0) · {{ $m['neighborhoods'] }} {{ \Illuminate\Support\Str::plural('neighborhood', $m['neighborhoods']) }}@endif</div>
                        </td>
                        <td>
                            <div class="mk-badges">
                                <x-lp.chip :tone="$m['tier'] === \App\Enums\MarketTier::Priority ? 'info' : 'neutral'">{{ $m['tier_label'] }}</x-lp.chip>
                                @if ($m['is_covered'])
                                    <x-lp.chip tone="good">Covered</x-lp.chip>
                                @endif
                            </div>
                        </td>
                        <td class="num">{{ $m['population'] > 0 ? number_format($m['population']) : '—' }}</td>
                        <td class="num">{{ $m['pages'] > 0 ? number_format($m['pages']) : '—' }}</td>
                        <td class="num">{{ $m['keywords'] > 0 ? number_format($m['keywords']) : '—' }}</td>
                        <td>
                            @if ($m['overdue'])
                                <x-lp.chip tone="bad">Overdue</x-lp.chip>
                                <div class="mk-hold-note">Release was due {{ $m['release_at'] }}</div>
                            @elseif ($m['on_hold'])
                                <x-lp.chip tone="warn">On hold</x-lp.chip>
                                <div class="mk-hold-note">Release target {{ $m['release_at'] ?? '—' }}</div>
                            @else
                                <span class="mk-dash">—</span>
                            @endif
                        </td>
                        <td class="num">
                            @if ($m['on_hold'])
                                <button type="button" class="mk-release"
                                    wire:click="release('{{ $m['id'] }}')"
                                    wire:confirm="Lift the hold on {{ $m['name'] }}?">Release</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-lp.shell>
