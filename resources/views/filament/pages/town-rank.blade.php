<x-filament-panels::page>
@php
    $cards = $this->cards;
    $board = $keywordId !== null ? $this->board : null;
    $town = $this->town;
    $rows = $this->visibleRows;
    $isLocal = $board !== null && $board['mode'] === \App\Models\TownRankScan::MODE_LOCAL;
    $prefix = $isLocal ? 'local' : 'town';
    $dotR = function (array $markers): callable {
        $max = max(1, (int) collect($markers)->max('population'));
        return fn (int $pop): float => round(1.4 + sqrt(max(0, $pop) / $max) * 2.4, 2);
    };
    $rankCell = fn ($rank, string $state): string => match ($state) {
        'unscanned' => '',
        'pending' => '…',
        'not_found' => 'not found',
        default => '#'.(int) $rank,
    };
    $levelColor = fn (string $level): string => match ($level) { 'do' => '#c0392b', 'watch' => '#ca8a04', default => '#15803d' };
    $moveView = $board !== null && $colorBy === 'move' && $board['has_previous'];
    $arrow = fn (?string $change): string => match ($change) { 'up' => '▲', 'down' => '▼', 'new' => '★', 'lost' => '✕', 'same' => '·', default => '' };
    $arrowColor = fn (?string $change): string => match ($change) { 'up' => '#15803d', 'down' => '#c0392b', 'new' => '#2563eb', 'lost' => '#7f1d1d', default => '#9ca3af' };
@endphp

<style>
    .trk { --t-line:#e2e7ee; --t-muted:#5a6675; --t-faint:#8a95a3; --t-surface:#ffffff; --t-surface2:#f6f8fb; }
    .dark .trk { --t-line:#232c37; --t-muted:#9aa7b5; --t-faint:#6b7887; --t-surface:#0b1017; --t-surface2:#0f151c; }
    .trk .t-chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; align-items:center; }
    .trk .t-kw { font-size:18px; font-weight:800; margin:0 8px 0 0; }
    .trk .t-modes { display:inline-flex; border:1px solid var(--t-line); border-radius:8px; overflow:hidden; margin-left:auto; }
    .trk .t-modes button { font-size:12px; padding:6px 12px; background:transparent; color:var(--t-muted); border:none; cursor:pointer; }
    .trk .t-modes button.on { background:rgba(37,99,235,.10); color:inherit; font-weight:600; }
    .trk .t-empty { padding:44px 16px; text-align:center; color:var(--t-muted); border:1px dashed var(--t-line); border-radius:12px; }
    .trk .t-buckets { display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:10px; margin-bottom:16px; }
    @media (max-width:820px){ .trk .t-buckets { grid-template-columns:repeat(3, minmax(0,1fr)); } }
    .trk .t-bucket { border:1px solid var(--t-line); border-radius:12px; padding:10px 12px; background:var(--t-surface); }
    .trk .t-bucket .n { font-size:24px; font-weight:800; line-height:1.1; font-variant-numeric:tabular-nums; }
    .trk .t-bucket .lab { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--t-muted); font-weight:700; margin-top:2px; }
    .trk .t-main { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(260px,1fr); gap:20px; align-items:start; }
    @media (max-width:820px){ .trk .t-main { grid-template-columns:1fr; } }
    .trk .t-mapwrap { border:1px solid var(--t-line); border-radius:14px; background:var(--t-surface2); padding:10px; }
    .trk .t-map { width:100%; height:auto; display:block; aspect-ratio:1/1; }
    .trk .t-dot { stroke:rgba(0,0,0,.25); stroke-width:.4; cursor:pointer; }
    .trk .t-dot.sel { stroke:#2563eb; stroke-width:1.2; }
    .trk .t-dot.nopage { stroke-dasharray:1 .6; }
    .trk .t-runall { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:-4px 0 14px; }
    .trk .t-runall button { font-size:12px; border:1px solid #2563eb; color:#2563eb; background:transparent; border-radius:8px; padding:6px 12px; cursor:pointer; font-weight:600; }
    .trk .t-runall button:disabled { opacity:.5; cursor:default; }
    .trk .t-legend { display:flex; gap:12px; flex-wrap:wrap; font-size:11px; color:var(--t-muted); margin-top:8px; }
    .trk .t-legend i { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:4px; vertical-align:middle; }
    .trk .t-panel { border:1px solid var(--t-line); border-radius:14px; padding:14px; background:var(--t-surface); }
    .trk .t-panel h3 { font-size:15px; font-weight:800; margin:0 0 2px; }
    .trk .t-panel .sub { font-size:12px; color:var(--t-muted); margin-bottom:10px; }
    .trk .t-krow { display:flex; justify-content:space-between; gap:10px; font-size:12.5px; padding:5px 0; border-bottom:1px solid var(--t-line); }
    .trk .t-krow:last-child { border-bottom:none; }
    .trk .t-krow b { font-variant-numeric:tabular-nums; white-space:nowrap; }
    .trk .t-act { border-left:3px solid; padding:6px 10px; margin-top:8px; font-size:12.5px; background:var(--t-surface2); border-radius:0 8px 8px 0; }
    .trk .t-act b { display:block; }
    .trk .t-act span { color:var(--t-muted); }
    .trk .t-actbtn { display:inline-block; margin-top:6px; padding:5px 11px; font-size:12px; font-weight:600; border-radius:7px; border:1px solid var(--t-line); background:var(--t-surface); color:inherit; cursor:pointer; }
    .trk .t-actbtn:hover { background:var(--t-surface2); }
    .trk .t-actbtn[disabled] { opacity:.6; cursor:progress; }
    .trk .t-comp { font-size:12px; color:var(--t-muted); margin:6px 0 0; padding-left:16px; }
    .trk h4.t-h { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--t-muted); font-weight:700; margin:14px 0 6px; }
    .trk .t-tablewrap { margin-top:20px; }
    .trk .t-filter { font-size:13px; border:1px solid var(--t-line); border-radius:8px; padding:7px 12px; background:transparent; color:inherit; min-width:240px; margin-bottom:10px; }
    .trk table.t-table { width:100%; border-collapse:collapse; font-size:12.5px; }
    .trk table.t-table th, .trk table.t-table td { border-bottom:1px solid var(--t-line); padding:6px 8px; text-align:left; }
    .trk table.t-table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--t-muted); }
    .trk table.t-table tr.row { cursor:pointer; }
    .trk table.t-table tr.row:hover, .trk table.t-table tr.row.sel { background:rgba(37,99,235,.07); }
    .trk .t-num { font-variant-numeric:tabular-nums; }
    .trk .t-note { font-size:12px; color:var(--t-faint); margin-top:8px; }
    .trk .t-back { font-size:12px; color:#2563eb; background:none; border:none; cursor:pointer; padding:0; margin-bottom:12px; display:inline-block; }
    .trk .t-cards { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px; }
    .trk .t-card { border:1px solid var(--t-line); border-radius:14px; background:var(--t-surface); padding:14px; color:inherit; }
    .trk .t-card:hover { border-color:#2563eb; }
    .trk .t-open { display:grid; grid-template-columns:96px minmax(0,1fr); gap:12px; align-items:start; width:100%; background:none; border:none; padding:0; cursor:pointer; text-align:left; color:inherit; }
    .trk .t-cardfoot { display:flex; align-items:center; gap:10px; margin-top:10px; padding-top:10px; border-top:1px solid var(--t-line); font-size:11.5px; color:var(--t-faint); }
    .trk .t-run { font-size:12px; border:1px solid #2563eb; color:#2563eb; background:transparent; border-radius:8px; padding:5px 10px; cursor:pointer; }
    .trk .t-run:disabled { opacity:.55; cursor:default; }
    .trk .t-rm { font-size:12px; border:1px solid var(--t-line); color:var(--t-muted); background:transparent; border-radius:8px; padding:5px 10px; cursor:pointer; margin-left:auto; }
    .trk .t-rm:hover { border-color:#c0392b; color:#c0392b; }
    .trk .t-silo { display:flex; align-items:baseline; gap:8px; margin:22px 0 10px; }
    .trk .t-silo:first-of-type { margin-top:4px; }
    .trk .t-silo h3 { font-size:13px; font-weight:800; margin:0; }
    .trk .t-silo span { font-size:11px; color:var(--t-faint); }
    .trk .t-silo hr { flex:1; border:none; border-top:1px solid var(--t-line); }
    .trk .t-add { display:flex; gap:8px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
    .trk .t-add input { font-size:13px; border:1px solid var(--t-line); border-radius:8px; padding:8px 12px; background:transparent; color:inherit; min-width:280px; }
    .trk .t-add button { font-size:13px; border:none; border-radius:8px; padding:8px 14px; background:#2563eb; color:#fff; cursor:pointer; }
    .trk .t-card .thumb { width:96px; height:96px; background:var(--t-surface2); border-radius:10px; display:block; }
    .trk .t-card h3 { font-size:14px; font-weight:800; margin:0 0 6px; }
    .trk .t-card .mrow { font-size:11.5px; color:var(--t-muted); margin:3px 0; display:flex; gap:8px; flex-wrap:wrap; }
    .trk .t-card .mrow b { color:inherit; font-variant-numeric:tabular-nums; }
    .trk .t-card .when { font-size:11px; color:var(--t-faint); margin-top:6px; }
</style>

<div class="trk" @if ($board !== null && $board['scan'] !== null && $board['scan']['status'] === 'pending') wire:poll.30s @endif>
    @if ($board === null)
        {{-- Card wall: add a keyword; one card per tracked / scanned keyword. Click a card → its board; Run → post its scans. --}}
        <div class="t-add">
            <input type="text" wire:model="newKeyword" wire:keydown.enter="addKeyword" placeholder="Add a keyword to track, e.g. sump pump repair" aria-label="Add keyword">
            <button type="button" wire:click="addKeyword" wire:loading.attr="disabled" wire:target="addKeyword">Add keyword</button>
            <span class="t-note" style="margin:0">A tracked keyword gets a card, a Run button, and joins the Monday sweep.</span>
        </div>
        {{-- Sitewide run: every keyword DUE for a re-scan, priced before it spends. The count and cost come
             from the same plan the run itself uses, so what is agreed to is what is posted. --}}
        @php $sweep = $this->sweepPlan; @endphp
        @if ($sweep !== null && $sweep['tracked'] > 0)
            <div class="t-runall">
                <button type="button" wire:click="runAllKeywords" wire:loading.attr="disabled" wire:target="runAllKeywords"
                        @disabled($sweep['runnable'] === 0)
                        wire:confirm="Queue {{ $sweep['runnable'] }} keyword(s) — {{ number_format($sweep['requests']) }} DataForSEO requests (~${{ number_format($sweep['cost'], 2) }}) across {{ number_format($sweep['towns']) }} towns? One job per keyword; a scan covers the whole site, so this runs once for every area at the same time.">
                    <span wire:loading.remove wire:target="runAllKeywords">Run all website rankings</span>
                    <span wire:loading wire:target="runAllKeywords">Queueing…</span>
                </button>
                @php
                    // Built here rather than as inline conditionals: the note is one sentence with two
                    // optional clauses, and Blade directives spliced mid-sentence compile badly.
                    $bits = [];
                    if ($sweep['pending'] > 0) { $bits[] = $sweep['pending'].' already collecting'; }
                    if ($sweep['blocked'] > 0) { $bits[] = $sweep['blocked'].' over the request ceiling'; }
                    $note = $sweep['runnable'] === 0
                        ? 'All '.$sweep['tracked'].' tracked keywords are already collecting or refused by the request ceiling.'
                        : $sweep['runnable'].' of '.$sweep['tracked'].' keywords · '.number_format($sweep['towns']).' towns'
                            .($bits === [] ? '' : ' · '.implode(' · ', $bits));
                @endphp
                <span class="t-note" style="margin:0">
                    {{ $note }}
                    @if ($sweep['runnable'] > 0)
                        · <b>{{ number_format($sweep['requests']) }} requests</b> · ~${{ number_format($sweep['cost'], 2) }}
                    @endif
                </span>
            </div>
        @endif
        @if ($cards === [])
            <div class="t-empty">No keywords tracked for Town Rank yet. Add one above, or run <code>launchpad:town-rank {site} --keyword="…" --scan --yes</code>.</div>
        @else
            @php($collecting = collect($cards)->contains(fn (array $c): bool => $c['pending']))
            {{-- While any card is collecting, refresh the wall every 30s so progress moves without a reload. --}}
            {{-- Grouped by the §4 silo the keyword belongs to, silos A→Z, "No silo" last — the content
                 architecture already names these, so the wall reads in the same terms. --}}
            @php($groups = collect($cards)->groupBy(fn (array $c): string => $c['silo'] ?? '')->all())
            <div @if ($collecting) wire:poll.30s @endif>
            @foreach ($groups as $siloName => $group)
                <div class="t-silo">
                    <h3>{{ $siloName !== '' ? $siloName : 'No silo' }}</h3>
                    <span>{{ count($group) }} keyword{{ count($group) === 1 ? '' : 's' }}{{ $siloName === '' ? ' · not assigned to a silo yet' : '' }}</span>
                    <hr>
                </div>
            <div class="t-cards">
                @foreach ($group as $card)
                    @php($cr = $dotR($card['markers']))
                    @php($runRequests = $card['towns'] * 2)
                    @php($runCost = $runRequests * \App\TownRank\TownRankScanner::costPerRequest())
                    <div class="t-card" wire:key="card-{{ $card['keyword_id'] }}">
                        <button type="button" class="t-open" wire:click="openKeyword('{{ $card['keyword_id'] }}')" title="Open {{ $card['query'] }}">
                            <svg class="thumb" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
                                @foreach ($card['markers'] as $m)
                                    <circle cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="{{ $cr($m['population']) }}" fill="{{ $m['color'] }}" />
                                @endforeach
                            </svg>
                            <div>
                                <h3>{{ $card['query'] }}</h3>
                                @if ($card['scanned_at'] === null)
                                    <div class="mrow"><span>Not scanned yet — run the ranking report, or wait for the Monday sweep.</span></div>
                                @else
                                    @foreach (['town_query' => 'Town search', 'local' => 'From town'] as $mode => $label)
                                        @php($s = $card['modes'][$mode])
                                        @php($pg = $card['progress'][$mode])
                                        <div class="mrow">
                                            <span>{{ $label }}:</span>
                                            @if ($s === null)
                                                <span>not scanned</span>
                                            @elseif (($card['uncollected'][$mode] ?? null) > 0)
                                                <span>top-3 <b style="color:#15803d">{{ $s['top3'] }}</b> page-1 <b>{{ $s['top3'] + $s['page1'] }}</b> not found <b style="color:#9ca3af">{{ $s['not_found'] }}</b></span>
                                                <span style="color:#b45309">· partial: {{ number_format($card['uncollected'][$mode]) }} town(s) never collected</span>
                                            @elseif ($pg !== null)
                                                <span style="color:#2563eb">collecting {{ number_format($pg['collected']) }} / {{ number_format($pg['points']) }} towns</span>
                                                @if ($pg['eta'] !== null)
                                                    <span style="color:#2563eb">· {{ number_format($pg['remaining']) }} left, at least {{ $pg['eta'] }}</span>
                                                @endif
                                                @if ($pg['collected'] > 0)<span>· so far: top-3 <b style="color:#15803d">{{ $s['top3'] }}</b> page-1 <b>{{ $s['top3'] + $s['page1'] }}</b> not found <b style="color:#9ca3af">{{ $s['not_found'] }}</b></span>@endif
                                            @else
                                                <span>top-3 <b style="color:#15803d">{{ $s['top3'] }}</b></span>
                                                <span>page-1 <b>{{ $s['top3'] + $s['page1'] }}</b></span>
                                                <span>not found <b style="color:#9ca3af">{{ $s['not_found'] }}</b></span>
                                                @if ($s['up'] + $s['down'] > 0)<span><b style="color:#15803d">▲{{ $s['up'] }}</b> <b style="color:#c0392b">▼{{ $s['down'] }}</b></span>@endif
                                            @endif
                                        </div>
                                    @endforeach
                                @endif
                                <div class="when">{{ $card['towns'] }} towns · {{ $card['scanned_at'] ? 'scanned '.\Illuminate\Support\Carbon::parse($card['scanned_at'])->diffForHumans() : 'never scanned' }}</div>
                            </div>
                        </button>
                        <div class="t-cardfoot">
                            <button type="button" class="t-run" wire:click="runKeyword('{{ $card['keyword_id'] }}')" wire:loading.attr="disabled" wire:target="runKeyword"
                                    wire:confirm="Post {{ number_format($runRequests) }} DataForSEO requests (~${{ number_format($runCost, 2) }}) for “{{ $card['query'] }}”? Results collect over the next few minutes."
                                    @disabled($card['pending'])>
                                {{ $card['pending'] ? 'Collecting…' : 'Run ranking report' }}
                            </button>
                            <span>{{ $card['pending'] ? 'a report is collecting — the card updates as results land' : number_format($runRequests).' requests · ~$'.number_format($runCost, 2) }}</span>
                            <button type="button" class="t-rm" wire:click="removeKeyword('{{ $card['keyword_id'] }}')" wire:loading.attr="disabled" wire:target="removeKeyword"
                                    wire:confirm="Remove “{{ $card['query'] }}” from the wall and the weekly sweep?{{ $card['scanned_at'] !== null ? ' Its collected scans are kept — add the keyword back and the history returns.' : '' }}">Remove</button>
                        </div>
                    </div>
                @endforeach
            </div>
            @endforeach
            </div>
        @endif
    @else
        <button type="button" class="t-back" wire:click="closeKeyword">← All keywords</button>
        {{-- Drill-down header: this keyword only (the wall is the place to pick another), plus the mode toggles. --}}
        <div class="t-chips">
            <h2 class="t-kw">{{ $board['keyword'] }}</h2>
            <div class="t-modes" role="group" aria-label="Query mode">
                <button type="button" class="{{ ! $isLocal ? 'on' : '' }}" wire:click="setMode('town_query')" title="&quot;keyword Town ST&quot; searched nationally — does the town page win its own search?">Town search</button>
                <button type="button" class="{{ $isLocal ? 'on' : '' }}" wire:click="setMode('local')" title="The bare keyword searched from the town — what a resident sees">Searched from town</button>
            </div>
            @if ($board['has_previous'])
                <div class="t-modes" role="group" aria-label="Colour by" style="margin-left:8px">
                    <button type="button" class="{{ ! $moveView ? 'on' : '' }}" wire:click="setView('rank')" title="Colour each town by its rank">Rank</button>
                    <button type="button" class="{{ $moveView ? 'on' : '' }}" wire:click="setView('move')" title="Colour each town by its movement since the previous scan">Movement</button>
                </div>
            @endif
        </div>

        @php($s = $board['summary'])
        @if ($board['scan'] === null)
            <div class="t-empty">No {{ $isLocal ? 'searched-from-town' : 'town-search' }} scan for “{{ $board['keyword'] }}” yet. Run <code>launchpad:town-rank {site} --keyword="{{ $board['keyword'] }}" --mode={{ $isLocal ? 'local' : 'town' }} --scan --yes</code>.</div>
        @else
            <div class="t-buckets">
                <div class="t-bucket"><div class="n" style="color:#15803d">{{ $s['top3'] }}</div><div class="lab">Top 3</div></div>
                <div class="t-bucket"><div class="n" style="color:#65a30d">{{ $s['page1'] }}</div><div class="lab">Page 1 (4–10)</div></div>
                <div class="t-bucket"><div class="n" style="color:#ca8a04">{{ $s['page2'] }}</div><div class="lab">Page 2</div></div>
                <div class="t-bucket"><div class="n" style="color:#c2410c">{{ $s['beyond'] }}</div><div class="lab">Beyond</div></div>
                <div class="t-bucket"><div class="n" style="color:#9ca3af">{{ $s['not_found'] }}</div><div class="lab">Not found{{ $s['pending'] > 0 ? ' · '.$s['pending'].' pending' : '' }}</div></div>
            </div>
            <div class="t-note">
                “{{ $board['keyword'] }}” · {{ $isLocal ? 'searched from each town' : 'town search: “'.$board['keyword'].' Town ST”' }} · {{ $board['scan']['points'] }} towns · {{ $board['scan']['status'] }}{{ $board['scan']['status'] === 'pending' ? ' — collecting '.number_format($board['scan']['collected']).' / '.number_format($board['scan']['points']) : '' }} {{ $board['scan']['scanned_at'] ? \Illuminate\Support\Carbon::parse($board['scan']['scanned_at'])->diffForHumans() : '' }}
                @if ($board['has_previous'])
                    · vs {{ \Illuminate\Support\Carbon::parse($board['scan']['previous_scanned_at'])->format('M j') }}: <span style="color:#15803d">▲{{ $s['up'] }}</span> <span style="color:#c0392b">▼{{ $s['down'] }}</span> · new {{ $s['new'] }} · lost {{ $s['lost'] }}
                @endif
            </div>
        @endif

        <div class="t-main" style="margin-top:12px">
            <div>
                <div class="t-mapwrap">
                    @php($r = $dotR($board['markers']))
                    <svg class="t-map" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Town rank map">
                        {{-- The county outlines first, as the background the dots sit on. --}}
                        @foreach ($board['outlines'] as $o)
                            <path class="t-county" d="{{ $o['paths'][0] ?? '' }}" pointer-events="none"><title>{{ $o['label'] }}</title></path>
                            @foreach (array_slice($o['paths'], 1) as $d)
                                <path class="t-county" d="{{ $d }}" pointer-events="none"></path>
                            @endforeach
                        @endforeach
                        @foreach ($board['markers'] as $m)
                            <circle class="t-dot {{ $m['id'] === $townId ? 'sel' : '' }} {{ $m['page'] ? '' : 'nopage' }}" cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="{{ $r($m['population']) }}" fill="{{ $moveView ? $m['delta_color'] : $m['color'] }}" wire:click="selectTown('{{ $m['id'] }}')">
                                <title>{{ $m['label'] }} — {{ $m['rank'] !== null ? '#'.$m['rank'] : 'not found' }}{{ $m['prev_rank'] !== null ? ' (was #'.$m['prev_rank'].')' : ($m['change'] === 'new' ? ' (new)' : '') }}{{ $m['page'] ? '' : ' · no page' }}@if ($m['population'] > 0) · pop {{ number_format($m['population']) }}@endif</title>
                            </circle>
                        @endforeach
                    </svg>
                    @if ($moveView)
                        <div class="t-legend">
                            <span><i style="background:#15803d"></i>moved up</span><span><i style="background:#c0392b"></i>slipped</span><span><i style="background:#2563eb"></i>newly ranking</span><span><i style="background:#7f1d1d"></i>lost</span><span><i style="background:#9ca3af"></i>unchanged / never ranked</span><span><i style="background:#7c3aed"></i>no data</span><span>dashed = no page</span>
                        </div>
                    @else
                        <div class="t-legend">
                            <span><i style="background:#15803d"></i>1–3</span><span><i style="background:#65a30d"></i>4–7</span><span><i style="background:#ca8a04"></i>8–10</span><span><i style="background:#c2410c"></i>11–15</span><span><i style="background:#c0392b"></i>16+</span><span><i style="background:#9ca3af"></i>not found</span><span><i style="background:#7c3aed"></i>no data</span><span>dashed = no page</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="t-panel">
                @if ($town === null)
                    <h3>Pick a town</h3>
                    <div class="sub">Click a dot on the map or a row in the table to see who outranks you there and what to do.</div>
                @else
                    <h3>{{ $town['label'] }}</h3>
                    <div class="sub">{{ $town['population'] > 0 ? 'pop '.number_format($town['population']).' · ' : '' }}{{ $town['page_state'] === 'anchored' ? 'page: '.$town['page_url'] : ($town['page_state'] === 'slug' ? 'page found by slug (GEOID differs): '.$town['page_url'] : 'no page') }}</div>
                    <div class="t-krow"><span>Town search</span><b>{{ $rankCell($town['town_query']['rank'], $town['town_query']['state']) ?: '—' }}@if ($town['town_query']['change'] !== null) <span style="color:{{ $arrowColor($town['town_query']['change']) }}">{{ $arrow($town['town_query']['change']) }}{{ $town['town_query']['prev_rank'] !== null ? ' was #'.$town['town_query']['prev_rank'] : '' }}</span>@endif</b></div>
                    <div class="t-krow"><span>Searched from town</span><b>{{ $rankCell($town['local']['rank'], $town['local']['state']) ?: '—' }}@if ($town['local']['change'] !== null) <span style="color:{{ $arrowColor($town['local']['change']) }}">{{ $arrow($town['local']['change']) }}{{ $town['local']['prev_rank'] !== null ? ' was #'.$town['local']['prev_rank'] : '' }}</span>@endif</b></div>
                    <div class="t-krow"><span>Map pack</span><b>{{ $town['map_rank'] !== null ? '#'.$town['map_rank'] : ($town['map_scanned'] ? 'not found' : '—') }}</b></div>

                    <h4 class="t-h">What to do</h4>
                    @foreach ($town['actions'] as $a)
                        <div class="t-act" style="border-color:{{ $levelColor($a['level']) }}">
                            <b>{{ $a['title'] }}</b><span>{{ $a['why'] }}</span>
                            {{-- The one action that can be done from here: the town has no page, so make one. --}}
                            @if ($a['key'] === 'build_page')
                                <button type="button" class="t-actbtn" wire:click="buildTownPage" wire:loading.attr="disabled" wire:target="buildTownPage"
                                        wire:confirm="Build a page for {{ $town['label'] }}? It is created and queued to draft; you review and publish it on Pages → Town.">
                                    <span wire:loading.remove wire:target="buildTownPage">Build this page</span>
                                    <span wire:loading wire:target="buildTownPage">Building…</span>
                                </button>
                            @endif
                        </div>
                    @endforeach

                    @if ($town['town_query']['competitors'] !== [])
                        <h4 class="t-h">Above you for the town search</h4>
                        <ol class="t-comp">
                            @foreach ($town['town_query']['competitors'] as $c)<li>#{{ $c['position'] }} {{ $c['domain'] }}</li>@endforeach
                        </ol>
                    @endif
                    @if ($town['local']['competitors'] !== [])
                        <h4 class="t-h">Above you searched from town</h4>
                        <ol class="t-comp">
                            @foreach ($town['local']['competitors'] as $c)<li>#{{ $c['position'] }} {{ $c['domain'] }}</li>@endforeach
                        </ol>
                    @endif
                @endif
            </div>
        </div>

        <div class="t-tablewrap">
            <input type="text" class="t-filter" wire:model.live.debounce.300ms="filter" placeholder="Filter towns…" aria-label="Filter towns">
            <table class="t-table">
                <thead><tr><th>Town</th><th>Pop</th><th>Page</th><th>Town search</th><th>From town</th><th>Map pack</th>@if ($board['has_previous'])<th>Δ {{ $isLocal ? 'from town' : 'town search' }}</th>@endif</tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="row {{ $row['coverage_area_id'] === $townId ? 'sel' : '' }}" wire:key="tr-{{ $row['coverage_area_id'] }}" wire:click="selectTown('{{ $row['coverage_area_id'] }}')">
                            <td>{{ $row['label'] }}{{ $row['state'] !== null ? ', '.$row['state'] : '' }}</td>
                            <td class="t-num">{{ $row['population'] > 0 ? number_format((int) $row['population']) : '—' }}</td>
                            <td>{{ $row['page_url'] !== null ? '✓' : '—' }}</td>
                            <td class="t-num">{{ $rankCell($row['town_rank'], (string) $row['town_state']) }}</td>
                            <td class="t-num">{{ $rankCell($row['local_rank'], (string) $row['local_state']) }}</td>
                            <td class="t-num">{{ $row['map_rank'] !== null ? '#'.$row['map_rank'] : '—' }}</td>
                            @if ($board['has_previous'])
                                @php($chg = $row[$prefix.'_change'])
                                <td class="t-num" style="color:{{ $arrowColor($chg) }}">{{ $arrow($chg) }}{{ $row[$prefix.'_prev_rank'] !== null && $chg !== 'same' && $chg !== null ? ' #'.$row[$prefix.'_prev_rank'] : '' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if (count($board['rows']) > count($rows))
                <div class="t-note">Showing {{ count($rows) }} of {{ count($board['rows']) }} towns — filter to narrow.</div>
            @endif
        </div>
    @endif
</div>
</x-filament-panels::page>
