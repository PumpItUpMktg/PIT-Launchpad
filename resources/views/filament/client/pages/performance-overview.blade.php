<x-filament-panels::page>
@php($accent = $branding['primary'] ?? '#0f62c4')

<style>
    .pd { --pd-accent: {{ $accent }}; --pd-ink:#111821; --pd-muted:#5a6675; --pd-faint:#8a95a3;
        --pd-surface:#fff; --pd-surface2:#f6f8fb; --pd-line:#e2e7ee; --pd-up:#1a8f52; --pd-up-soft:#e2f2e9;
        --pd-work:#b9770a; color:var(--pd-ink); }
    .dark .pd { --pd-ink:#e7edf4; --pd-muted:#9aa7b5; --pd-faint:#6b7887; --pd-surface:#131a22;
        --pd-surface2:#0f151c; --pd-line:#232c37; --pd-up:#4cc07f; --pd-up-soft:#16281e; --pd-work:#d99a34; }
    .pd .pd-card { background:var(--pd-surface); border:1px solid var(--pd-line); border-radius:14px; padding:20px; }
    .pd .pd-controls { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
    .pd .pd-frame { display:inline-flex; background:var(--pd-surface2); border:1px solid var(--pd-line); border-radius:10px; padding:3px; }
    .pd .pd-frame button { font-size:13px; font-weight:600; border:0; background:transparent; color:var(--pd-muted); padding:6px 13px; border-radius:7px; cursor:pointer; }
    .pd .pd-frame button.on { background:var(--pd-surface); color:var(--pd-ink); box-shadow:0 1px 3px rgba(0,0,0,.12); }
    .pd .pd-select { font-size:13px; background:var(--pd-surface); border:1px solid var(--pd-line); border-radius:9px; padding:7px 12px; color:var(--pd-ink); }
    .pd .pd-spacer { flex:1 1 auto; }
    .pd .pd-hero { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; }
    @media (max-width:900px){ .pd .pd-hero{ grid-template-columns:1fr; } .pd .pd-cols{ grid-template-columns:1fr !important; } }
    .pd .pd-eyebrow { font-size:11.5px; text-transform:uppercase; letter-spacing:.08em; color:var(--pd-muted); font-weight:600; }
    .pd .pd-big { font-weight:800; font-size:44px; line-height:1.02; letter-spacing:-.02em; margin:8px 0 2px; font-variant-numeric:tabular-nums; }
    .pd .pd-big .pd-unit { font-size:15px; font-weight:600; color:var(--pd-muted); }
    .pd .pd-say { font-size:13.5px; color:var(--pd-muted); }
    .pd .pd-foot { margin-top:14px; padding-top:13px; border-top:1px solid var(--pd-line); display:flex; align-items:center; gap:8px; }
    .pd .pd-delta { display:inline-flex; align-items:center; gap:5px; font-weight:700; font-size:13px; padding:3px 9px; border-radius:999px; color:var(--pd-up); background:var(--pd-up-soft); }
    .pd .pd-cols { display:grid; grid-template-columns:1.35fr 1fr; gap:16px; align-items:start; margin-top:16px; }
    .pd .pd-colstack { display:grid; gap:16px; }
    .pd .pd-h { font-size:15px; font-weight:700; margin:0 0 12px; }
    .pd .pd-legend { display:flex; gap:16px; font-size:12.5px; color:var(--pd-muted); flex-wrap:wrap; }
    .pd .pd-legend i { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:6px; vertical-align:-1px; }
    .pd .pd-band { display:grid; grid-template-columns:64px 1fr 34px; align-items:center; gap:12px; font-size:13px; margin-bottom:10px; }
    .pd .pd-band .lbl { color:var(--pd-muted); font-weight:600; }
    .pd .pd-track { height:9px; border-radius:999px; background:var(--pd-surface2); overflow:hidden; }
    .pd .pd-fill { height:100%; border-radius:999px; background:var(--pd-accent); }
    .pd .pd-band .val { text-align:right; font-weight:700; font-variant-numeric:tabular-nums; }
    .pd .pd-movers { list-style:none; margin:14px 0 0; padding:14px 0 0; border-top:1px solid var(--pd-line); display:grid; gap:11px; }
    .pd .pd-movers li { display:flex; align-items:center; gap:10px; font-size:13.5px; }
    .pd .pd-q { flex:1 1 auto; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .pd .pd-rank { color:var(--pd-muted); font-weight:600; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .pd .pd-jump { color:var(--pd-up); font-weight:700; white-space:nowrap; }
    .pd .pd-chip { font-size:11px; font-weight:700; padding:1px 7px; border-radius:999px; background:var(--pd-up-soft); color:var(--pd-up); }
    .pd .pd-timeline { list-style:none; margin:0; padding:0; position:relative; }
    .pd .pd-timeline::before { content:""; position:absolute; left:7px; top:6px; bottom:6px; width:2px; background:var(--pd-line); }
    .pd .pd-timeline li { position:relative; padding:0 0 15px 28px; }
    .pd .pd-timeline li:last-child { padding-bottom:0; }
    .pd .pd-dot { position:absolute; left:0; top:3px; width:16px; height:16px; border-radius:50%; background:var(--pd-accent); border:3px solid var(--pd-surface); box-shadow:0 0 0 1px var(--pd-line); }
    .pd .pd-ev { font-weight:600; font-size:14px; }
    .pd .pd-dt { font-size:12px; color:var(--pd-faint); }
    .pd .pd-note { margin-top:22px; padding:15px 17px; border:1px solid var(--pd-line); border-radius:12px; background:var(--pd-surface2); font-size:12.5px; color:var(--pd-muted); }
    .pd .pd-empty { text-align:center; padding:56px 20px; color:var(--pd-muted); }
    .pd .pd-empty h3 { font-size:18px; color:var(--pd-ink); margin:0 0 6px; }
    .pd svg.pd-chart { width:100%; height:auto; display:block; }
</style>

<div class="pd">

    @if (! $ready)
        <div class="pd-card pd-empty">
            <h3>No site to show yet</h3>
            <p>Once your site is set up and Google starts reporting, your performance will appear here.</p>
        </div>
    @else

        {{-- controls: frame toggle + (optional) site switcher --}}
        <div class="pd-controls">
            <div class="pd-frame" role="group" aria-label="Time frame">
                @foreach ($frames as $key => $label)
                    <button type="button" wire:click="setFrame('{{ $key }}')" @class(['on' => $key === $frameKey])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="pd-spacer"></div>
            @if (count($siteOptions) > 1)
                <select class="pd-select" wire:model.live="siteId" aria-label="Site">
                    @foreach ($siteOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        {{-- hero --}}
        <div class="pd-hero">
            <div class="pd-card">
                <div class="pd-eyebrow">Pages working</div>
                <div class="pd-big">{{ number_format($hero['pages_working']['value']) }}</div>
                <div class="pd-say">pages earning Google Search impressions</div>
                <div class="pd-foot">
                    @if ($hero['pages_working']['delta'] > 0)
                        <span class="pd-delta">▲ {{ number_format($hero['pages_working']['delta']) }}</span>
                    @endif
                    <span class="pd-say">in the last 28 days</span>
                </div>
            </div>
            <div class="pd-card">
                <div class="pd-eyebrow">Keywords improved</div>
                <div class="pd-big">{{ number_format($hero['keywords_improved']['value']) }}</div>
                <div class="pd-say">keywords moved up in Google</div>
                <div class="pd-foot">
                    @if ($hero['keywords_improved']['reached_page1'] > 0)
                        <span class="pd-delta">▲ {{ $hero['keywords_improved']['reached_page1'] }}</span>
                    @endif
                    <span class="pd-say">reached page one</span>
                </div>
            </div>
            <div class="pd-card">
                <div class="pd-eyebrow">Pages Google added</div>
                <div class="pd-big">{{ number_format($hero['pages_added']['indexed']) }}<span class="pd-unit"> / {{ number_format($hero['pages_added']['total']) }}</span></div>
                <div class="pd-say">of your pages are indexed by Google</div>
                <div class="pd-foot">
                    @if ($hero['pages_added']['delta'] > 0)
                        <span class="pd-delta">▲ {{ number_format($hero['pages_added']['delta']) }}</span>
                    @endif
                    <span class="pd-say">newly indexed in the last 28 days</span>
                </div>
            </div>
        </div>

        <div class="pd-cols">
            {{-- left column --}}
            <div class="pd-colstack">
                <div class="pd-card">
                    <h2 class="pd-h">Search visibility</h2>
                    @if ($charts['visibility']['has_data'])
                        <div class="pd-legend" style="margin-bottom:10px">
                            <span><i style="background:var(--pd-accent)"></i>Impressions</span>
                            <span><i style="background:var(--pd-up)"></i>Clicks</span>
                            @if (! empty($charts['visibility']['markers']))<span><i style="background:var(--pd-work);border-radius:50%"></i>Milestone</span>@endif
                        </div>
                        <svg class="pd-chart" viewBox="0 0 620 200" preserveAspectRatio="none" role="img" aria-label="Search impressions and clicks over time">
                            @if ($charts['visibility']['imprArea'])<path d="{{ $charts['visibility']['imprArea'] }}" fill="var(--pd-accent)" fill-opacity="0.14"/>@endif
                            <polyline points="{{ $charts['visibility']['imprLine'] }}" fill="none" stroke="var(--pd-accent)" stroke-width="2.5"/>
                            <polyline points="{{ $charts['visibility']['clickLine'] }}" fill="none" stroke="var(--pd-up)" stroke-width="2.5"/>
                            @foreach ($charts['visibility']['markers'] as $mk)
                                <line x1="{{ $mk['x'] }}" y1="6" x2="{{ $mk['x'] }}" y2="194" stroke="var(--pd-work)" stroke-width="1" stroke-dasharray="3 3" opacity="0.6"/>
                                <circle cx="{{ $mk['x'] }}" cy="8" r="4" fill="var(--pd-work)"><title>{{ $mk['label'] }}</title></circle>
                            @endforeach
                        </svg>
                        <div class="pd-legend" style="margin-top:10px; justify-content:space-between">
                            <span>{{ $meta['launch_date'] ? \Illuminate\Support\Carbon::parse($meta['launch_date'])->isoFormat('MMM YYYY') : '' }}</span>
                            <span>Data through {{ $meta['data_through'] ? \Illuminate\Support\Carbon::parse($meta['data_through'])->isoFormat('MMM D, YYYY') : '—' }}</span>
                        </div>
                    @else
                        <p class="pd-say">Google is still collecting Search data for your site — check back soon.</p>
                    @endif
                </div>

                <div class="pd-card">
                    <h2 class="pd-h">Keyword standings</h2>
                    @php($mx = max(1, $standings['top20']))
                    <div class="pd-band"><span class="lbl">Page 1</span><span class="pd-track"><span class="pd-fill" style="width:{{ round(min(1,$standings['top10']/$mx)*100) }}%"></span></span><span class="val">{{ $standings['top10'] }}</span></div>
                    <div class="pd-band"><span class="lbl">Top 3</span><span class="pd-track"><span class="pd-fill" style="width:{{ round(min(1,$standings['top3']/$mx)*100) }}%"></span></span><span class="val">{{ $standings['top3'] }}</span></div>
                    <div class="pd-band"><span class="lbl">Top 20</span><span class="pd-track"><span class="pd-fill" style="width:{{ round(min(1,$standings['top20']/$mx)*100) }}%"></span></span><span class="val">{{ $standings['top20'] }}</span></div>

                    @if (! empty($standings['movers']))
                        <ul class="pd-movers">
                            @foreach ($standings['movers'] as $m)
                                <li>
                                    <span class="pd-q">{{ $m['query'] ?? 'a keyword' }}</span>
                                    @if ($m['new'])
                                        <span class="pd-rank">now #{{ $m['to'] }}</span><span class="pd-chip">NEW</span>
                                    @else
                                        <span class="pd-rank">{{ $m['from'] }} → {{ $m['to'] }}</span><span class="pd-jump">▲ {{ $m['jump'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- right column --}}
            <div class="pd-colstack">
                <div class="pd-card">
                    <h2 class="pd-h">Milestones</h2>
                    @if (! empty($milestones))
                        <ul class="pd-timeline">
                            @foreach ($milestones as $ms)
                                <li>
                                    <span class="pd-dot"></span>
                                    <div class="pd-ev">{{ $ms['label'] }}</div>
                                    <div class="pd-dt">{{ \Illuminate\Support\Carbon::parse($ms['date'])->isoFormat('MMM D, YYYY') }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="pd-say">Your first milestones will appear as Google indexes your pages and keywords start ranking.</p>
                    @endif
                </div>

                <div class="pd-card">
                    <h2 class="pd-h">Pages indexed</h2>
                    @if ($charts['index']['has_data'])
                        <svg class="pd-chart" viewBox="0 0 320 150" preserveAspectRatio="none" role="img" aria-label="Indexed pages over time versus total pages">
                            @if ($charts['index']['indexedArea'])<path d="{{ $charts['index']['indexedArea'] }}" fill="var(--pd-up)" fill-opacity="0.14"/>@endif
                            <polyline points="{{ $charts['index']['knownLine'] }}" fill="none" stroke="var(--pd-faint)" stroke-width="1.5" stroke-dasharray="4 4"/>
                            <polyline points="{{ $charts['index']['indexedLine'] }}" fill="none" stroke="var(--pd-up)" stroke-width="2.5"/>
                        </svg>
                        <div class="pd-legend" style="margin-top:8px">
                            <span><i style="background:var(--pd-up)"></i>Indexed ({{ $hero['pages_added']['indexed'] }})</span>
                            <span><i style="background:var(--pd-faint)"></i>Total pages ({{ $hero['pages_added']['total'] }})</span>
                        </div>
                    @else
                        <p class="pd-say">Indexing data will appear once Google inspects your pages.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="pd-note">
            Refresh markers annotate the trend as <strong>observed correlation</strong> — not a guarantee of cause.
            Figures come from Google Search Console and rank tracking, refreshed daily{{ $meta['data_through'] ? ', data through '.\Illuminate\Support\Carbon::parse($meta['data_through'])->isoFormat('MMM D, YYYY') : '' }}.
        </div>

    @endif
</div>
</x-filament-panels::page>
