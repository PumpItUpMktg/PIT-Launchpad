<x-filament-panels::page>
    <style>
        .tp-wrap { display:flex; flex-direction:column; gap:14px; }
        .tp-head { display:flex; justify-content:space-between; align-items:flex-end; gap:14px; flex-wrap:wrap; }
        .tp-sub { color:#64748b; font-size:13px; max-width:70ch; margin:4px 0 0; }
        .tp-select { font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:5px 9px; background:transparent; }
        .tp-market { border:1px solid rgba(148,163,184,.35); border-radius:11px; overflow:hidden; }
        .tp-market[open] > summary { border-bottom:1px solid rgba(148,163,184,.2); }
        .tp-msum { list-style:none; cursor:pointer; display:flex; align-items:center; gap:12px; padding:12px 15px; background:rgba(148,163,184,.06); }
        .tp-msum::-webkit-details-marker { display:none; }
        .tp-msum h3 { margin:0; font-size:15px; }
        .tp-mstats { display:flex; gap:14px; font-size:12.5px; color:#64748b; margin-left:auto; }
        .tp-mstats b { font-variant-numeric:tabular-nums; color:#334155; }
        .tp-prob { font-size:11px; font-weight:700; padding:2px 9px; border-radius:99px; background:rgba(220,38,38,.12); color:#dc2626; white-space:nowrap; }
        .tp-bands { padding:12px 15px; display:flex; flex-direction:column; gap:12px; }
        .tp-band { border:1px solid rgba(148,163,184,.25); border-radius:9px; padding:11px 13px; }
        .tp-band.locked { opacity:.6; background:rgba(148,163,184,.05); }
        .tp-brow { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .tp-btier { font-size:13px; font-weight:700; }
        .tp-bcount { font-size:12px; color:#64748b; font-variant-numeric:tabular-nums; }
        .tp-state { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:2px 8px; border-radius:99px; margin-left:auto; }
        .tp-state.complete { background:rgba(22,163,74,.13); color:#16a34a; }
        .tp-state.indexing { background:rgba(37,99,235,.12); color:#2563eb; }
        .tp-state.ready { background:rgba(148,163,184,.18); color:#475569; }
        .tp-state.locked { background:rgba(100,116,139,.15); color:#64748b; }
        .tp-lock { font-size:11.5px; color:#94a3b8; margin-top:6px; display:flex; align-items:center; gap:5px; }
        .tp-bar { height:6px; border-radius:99px; background:rgba(148,163,184,.25); margin-top:8px; overflow:hidden; }
        .tp-bar > i { display:block; height:100%; background:#2563eb; border-radius:99px; }
        .tp-pills { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; }
        .tp-pill { font-size:11.5px; border-radius:99px; padding:2px 9px; display:inline-flex; align-items:center; gap:6px; border:1px solid transparent; white-space:nowrap; }
        .tp-pill.indexed { background:rgba(22,163,74,.11); color:#15803d; border-color:rgba(22,163,74,.3); }
        .tp-pill.pending { background:rgba(217,119,6,.11); color:#b45309; border-color:rgba(217,119,6,.28); }
        .tp-pill.failed  { background:rgba(220,38,38,.10); color:#dc2626; border-color:rgba(220,38,38,.3); }
        .tp-pill.unknown { background:rgba(148,163,184,.13); color:#64748b; border-color:rgba(148,163,184,.3); }
        .tp-in { font-variant-numeric:tabular-nums; font-size:10px; font-weight:700; opacity:.8; }
        .tp-in.zero { color:#dc2626; opacity:1; }
        .tp-empty { color:#94a3b8; font-size:13px; padding:24px 4px; }
    </style>

    <div class="tp-wrap">
        <div class="tp-head">
            <div>
                <p class="tp-sub">Town pages by market, then size tier. A tier unlocks once the tier above it
                    indexes — build the largest tier, index it, then let its links pull the next tier in. The
                    <b>inbound-link count</b> on each pill is the leading signal: a town with zero inbound links
                    (shown red) won't be crawled quickly whatever its tier.</p>
            </div>
        </div>

        @forelse ($this->progression as $market)
            <details class="tp-market" @if ($market['has_problem']) open @endif>
                <summary class="tp-msum">
                    <h3>{{ $market['name'] }}</h3>
                    <div class="tp-mstats">
                        <span><b>{{ $market['built'] }}</b> built</span>
                        <span><b>{{ $market['served'] }}</b> served</span>
                        @if ($market['problem_count'] > 0)
                            <span class="tp-prob">{{ $market['problem_count'] }} not indexed</span>
                        @endif
                    </div>
                </summary>

                <div class="tp-bands">
                    @foreach ($market['tiers'] as $band)
                        <div class="tp-band {{ $band['state'] }}">
                            <div class="tp-brow">
                                <span class="tp-btier">{{ $band['label'] }}</span>
                                <span class="tp-bcount">{{ $band['built'] }}/{{ $band['served'] }} built · {{ $band['indexed'] }} indexed</span>
                                <span class="tp-state {{ $band['state'] }}">{{ $band['state'] }}</span>
                            </div>

                            @if ($band['state'] === 'indexing')
                                <div class="tp-bar"><i style="width: {{ (int) round($band['progress'] * 100) }}%"></i></div>
                            @endif

                            @if ($band['unlock'])
                                <div class="tp-lock">🔒 {{ $band['unlock'] }}</div>
                            @endif

                            @if (! empty($band['towns']))
                                <div class="tp-pills">
                                    @foreach ($band['towns'] as $town)
                                        <span class="tp-pill {{ $town['index_state'] }}" title="{{ $town['inbound_links'] }} inbound link(s) · {{ $town['index_state'] }}">
                                            {{ $town['name'] }}
                                            <span class="tp-in {{ $town['inbound_links'] === 0 ? 'zero' : '' }}">↳{{ $town['inbound_links'] }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </details>
        @empty
            <p class="tp-empty">No town pages for this site yet.</p>
        @endforelse
    </div>
</x-filament-panels::page>
