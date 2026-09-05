<x-lp.shell
    variant="table"
    eyebrow="Results"
    title="Indexing"
    lede="How much of the tenant is in Google's index. The pages Launchpad published (your sitemap) are what you can act on; the rest are URLs Google merely found. The reasons a URL isn't indexed are the point — not the raw count.">

    @php($board = $this->board)

    <style>
        .ix-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; align-items:start; }
        @media (max-width:900px){ .ix-grid { grid-template-columns:1fr; } }
        .ix-card { background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        .ix-head { padding:14px 16px; border-bottom:1px solid var(--line); background:var(--paper); }
        .ix-head .t { font-family:'Archivo',sans-serif; font-size:14px; font-weight:700; color:var(--ink); }
        .ix-head .d { font-size:12px; color:var(--ink-soft); margin-top:2px; }
        .ix-nums { display:flex; gap:20px; padding:14px 16px; flex-wrap:wrap; }
        .ix-num .n { font-family:'Spline Sans Mono',monospace; font-size:22px; font-weight:600; }
        .ix-num .n.good { color:#2E7D6B; } .ix-num .n.warn { color:var(--amber); } .ix-num .n.neutral { color:var(--ink-soft); }
        .ix-num .l { font-size:11px; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
        .ix-bar { height:8px; display:flex; margin:0 16px 14px; border-radius:5px; overflow:hidden; background:var(--paper); }
        .ix-bar i { display:block; height:100%; }
        .ix-bar .ok { background:#2E7D6B; } .ix-bar .ex { background:#4E9A98; } .ix-bar .no { background:#B5731A; }
        .ix-reasons { width:100%; border-collapse:collapse; font-size:13px; }
        .ix-reasons th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft); font-weight:700; padding:8px 16px; border-top:1px solid var(--line); }
        .ix-reasons td { padding:8px 16px; border-top:1px solid #EEF2F4; }
        .ix-reasons td.num { text-align:right; font-variant-numeric:tabular-nums; font-weight:700; }
        .ix-reasons tr:first-child td { border-top:1px solid var(--line); }
        .ix-none { padding:14px 16px; color:var(--ink-soft); font-size:12.5px; }
        .ix-note { font-size:12.5px; color:var(--ink-soft); background:var(--paper); border:1px solid var(--line); border-radius:10px; padding:12px 16px; margin-top:16px; }
    </style>

    @php($pub = $board['published'])
    @php($all = $board['all_known'])

    @if ($this->siteId === null)
        <x-lp.empty title="No tenant selected" action="Go to Portfolio" :href="\App\Filament\Resources\SiteResource::getUrl('index')">
            Pick a working tenant from the topbar to see its index coverage.
        </x-lp.empty>
    @elseif ($all['total'] === 0)
        <x-lp.empty title="No index data yet" action="Open Pages" :href="\App\Filament\Pages\Operate\OperatePages::getUrl()">
            Index coverage appears once <code>sandhog:sync-index</code> has inspected this tenant's URLs against Google Search Console. Nothing has been synced yet.
        </x-lp.empty>
    @else
        <div class="ix-grid">
            {{-- Published (in sitemap) — the actionable set --}}
            @php($pt = max(1, $pub['total']))
            <div class="ix-card">
                <div class="ix-head">
                    <div class="t">Pages you published <span style="color:var(--ink-soft);font-weight:600">— in your sitemap</span></div>
                    <div class="d">The town + service pages Launchpad built. This is the coverage you can act on.</div>
                </div>
                <div class="ix-nums">
                    <div class="ix-num"><div class="n good">{{ number_format($pub['indexed']) }}</div><div class="l">Indexed</div></div>
                    <div class="ix-num"><div class="n {{ $pub['not_indexed'] ? 'warn' : 'neutral' }}">{{ number_format($pub['not_indexed']) }}</div><div class="l">Not indexed</div></div>
                    <div class="ix-num"><div class="n neutral">{{ number_format($pub['excluded']) }}</div><div class="l">Excluded (correct)</div></div>
                    <div class="ix-num"><div class="n neutral">{{ number_format($pub['total']) }}</div><div class="l">Published</div></div>
                </div>
                <div class="ix-bar"><i class="ok" style="width:{{ round($pub['indexed'] / $pt * 100) }}%"></i><i class="ex" style="width:{{ round($pub['excluded'] / $pt * 100) }}%"></i><i class="no" style="width:{{ round($pub['not_indexed'] / $pt * 100) }}%"></i></div>
                @if (empty($pub['reasons']))
                    <div class="ix-none">Every published page is indexed or correctly excluded — nothing pending.</div>
                @else
                    <table class="ix-reasons">
                        <thead><tr><th>Why a published page isn't indexed</th><th style="text-align:right">URLs</th></tr></thead>
                        <tbody>@foreach ($pub['reasons'] as $r)<tr><td>{{ $r['label'] }}</td><td class="num">{{ number_format($r['count']) }}</td></tr>@endforeach</tbody>
                    </table>
                @endif
            </div>

            {{-- All known to Google — the context --}}
            @php($at = max(1, $all['total']))
            <div class="ix-card">
                <div class="ix-head">
                    <div class="t">All URLs Google knows <span style="color:var(--ink-soft);font-weight:600">— incl. found outside your sitemap</span></div>
                    <div class="d"><b>{{ number_format($board['discovered_only']) }}</b> are URLs Google found on its own (WordPress archives, params) — not pages you published.</div>
                </div>
                <div class="ix-nums">
                    <div class="ix-num"><div class="n good">{{ number_format($all['indexed']) }}</div><div class="l">Indexed</div></div>
                    <div class="ix-num"><div class="n {{ $all['not_indexed'] ? 'warn' : 'neutral' }}">{{ number_format($all['not_indexed']) }}</div><div class="l">Not indexed</div></div>
                    <div class="ix-num"><div class="n neutral">{{ number_format($all['excluded']) }}</div><div class="l">Excluded (correct)</div></div>
                    <div class="ix-num"><div class="n neutral">{{ number_format($all['total']) }}</div><div class="l">All known</div></div>
                </div>
                <div class="ix-bar"><i class="ok" style="width:{{ round($all['indexed'] / $at * 100) }}%"></i><i class="ex" style="width:{{ round($all['excluded'] / $at * 100) }}%"></i><i class="no" style="width:{{ round($all['not_indexed'] / $at * 100) }}%"></i></div>
                @if (empty($all['reasons']))
                    <div class="ix-none">Nothing pending across all known URLs.</div>
                @else
                    <table class="ix-reasons">
                        <thead><tr><th>Why a URL isn't indexed</th><th style="text-align:right">URLs</th></tr></thead>
                        <tbody>@foreach ($all['reasons'] as $r)<tr><td>{{ $r['label'] }}</td><td class="num">{{ number_format($r['count']) }}</td></tr>@endforeach</tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="ix-note">
            A big "not indexed" number is usually the URLs on the right — archives and parameter pages Google discovered that were never meant to rank. What matters is the left: of your <b>{{ number_format($pub['total']) }}</b> published pages, <b>{{ number_format($pub['not_indexed']) }}</b> aren't indexed. Indexed = a URL-Inspection <code>PASS</code>; a redirect or canonical is a correct exclusion, not pending.
        </div>
    @endif
</x-lp.shell>
