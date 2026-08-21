<x-filament-panels::page>
    @include('filament.console.partials.site-switcher')

    @php $health = $this->health; $failures = $this->failures; $locked = $this->locked; @endphp

    <style>
        .cr-wrap { display:flex; flex-direction:column; gap:18px; }
        .cr-tiles { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px,1fr)); gap:10px; }
        .cr-tile { border:1px solid rgba(148,163,184,.35); border-radius:10px; padding:11px 13px; }
        .cr-tile .n { font-size:22px; font-weight:700; font-variant-numeric:tabular-nums; }
        .cr-tile .l { font-size:11px; color:#94a3b8; }
        .cr-tile.hot { border-color:rgba(220,38,38,.5); } .cr-tile.hot .n { color:#dc2626; }
        .cr-tile.ok { border-color:rgba(22,163,74,.45); } .cr-tile.ok .n { color:#15803d; }
        .cr-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:14px 16px; display:flex; flex-direction:column; gap:11px; }
        .cr-card h3 { margin:0; font-size:14.5px; }
        .cr-row { display:flex; gap:9px; flex-wrap:wrap; }
        .cr-btn { font-size:12.5px; font-weight:600; padding:7px 14px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; }
        .cr-btn.warn { border-color:rgba(217,119,6,.5); color:#b45309; }
        .cr-btn.danger { border-color:rgba(220,38,38,.5); color:#dc2626; }
        .cr-fail { font-size:12px; color:#64748b; border-left:2px solid rgba(220,38,38,.4); padding:2px 0 2px 10px; }
        .cr-lock { display:flex; align-items:center; gap:10px; justify-content:space-between; border:1px solid rgba(148,163,184,.3); border-radius:9px; padding:8px 12px; }
        .cr-empty { color:#94a3b8; font-size:13px; }
        .cov-row { border:1px solid rgba(148,163,184,.3); border-radius:9px; margin-bottom:7px; }
        .cov-row summary { list-style:none; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:9px 13px; }
        .cov-row summary::-webkit-details-marker { display:none; }
        .cov-row summary::before { content:'▸'; color:#94a3b8; font-size:11px; }
        .cov-row[open] summary::before { content:'▾'; }
        .cov-name { font-weight:700; font-size:13px; flex:1; }
        .cov-cells { display:flex; gap:14px; font-size:12px; font-variant-numeric:tabular-nums; align-items:center; flex-wrap:wrap; }
        .cov-green { color:#15803d; font-weight:700; } .cov-grey { color:#94a3b8; font-weight:700; }
        .cov-detail { padding:4px 13px 12px; border-top:1px solid rgba(148,163,184,.2); }
        .cov-extra { font-size:11.5px; color:#64748b; margin:8px 0 10px; }
        .cov-page { display:flex; align-items:center; gap:9px; padding:4px 0; border-top:1px dashed rgba(148,163,184,.2); font-size:12px; }
        .cov-ptitle { flex:1; color:#334155; }
        .cov-pmetrics { color:#94a3b8; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .cov-pill { font-size:10px; font-weight:700; padding:1px 8px; border-radius:99px; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
        .cov-pill::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
        .cov-pill.unsubmitted { background:rgba(148,163,184,.18); color:#64748b; }
        .cov-pill.submitted { background:rgba(202,138,4,.16); color:#a16207; }
        .cov-pill.indexed { background:rgba(22,163,74,.16); color:#15803d; }
    </style>

    <div class="cr-wrap">
        <div class="cr-tiles">
            <div class="cr-tile {{ $health['stalled'] ? 'hot' : 'ok' }}">
                <div class="n">{{ $health['stalled'] ? 'Stalled' : 'Healthy' }}</div><div class="l">worker</div>
            </div>
            <div class="cr-tile {{ $health['pending'] > 0 ? '' : '' }}"><div class="n">{{ $health['pending'] }}</div><div class="l">pending jobs</div></div>
            <div class="cr-tile {{ $health['failed'] > 0 ? 'hot' : '' }}"><div class="n">{{ $health['failed'] }}</div><div class="l">failed jobs</div></div>
            <div class="cr-tile"><div class="n">{{ $health['oldest_minutes'] }}m</div><div class="l">oldest waiting</div></div>
        </div>

        @php $cov = $this->coverage; @endphp
        @if ($cov)
            <div class="cr-card">
                <h3>Index &amp; visibility</h3>
                <div class="cr-empty">Live URLs by page type — indexed vs not, and 28-day Search visibility, most-visible first. Expand a row for its pages (spot the risers to poke and the zeros that aren’t earning). Reads cached index verdicts + the GSC store — no new API calls; run “Refresh index coverage” below to freshen the verdicts.</div>
                <div class="cr-tiles">
                    <div class="cr-tile"><div class="n">{{ number_format($cov['totals']['total']) }}</div><div class="l">live URLs</div></div>
                    <div class="cr-tile ok"><div class="n">{{ number_format($cov['totals']['indexed']) }}</div><div class="l">indexed · {{ $cov['totals']['indexed_pct'] }}%</div></div>
                    <div class="cr-tile"><div class="n">{{ number_format($cov['totals']['not_indexed']) }}</div><div class="l">not indexed</div></div>
                    <div class="cr-tile"><div class="n">{{ number_format($cov['totals']['impressions']) }}</div><div class="l">impr · {{ $cov['window_days'] }}d</div></div>
                    <div class="cr-tile"><div class="n">{{ number_format($cov['totals']['clicks']) }}</div><div class="l">clicks · {{ $cov['window_days'] }}d</div></div>
                </div>
                <div style="margin-top:12px">
                    @forelse ($cov['groups'] as $g)
                        <details class="cov-row">
                            <summary>
                                <span class="cov-name">{{ $g['label'] }}</span>
                                <span class="cov-cells">
                                    <span title="total pages">{{ $g['total'] }} total</span>
                                    <span class="cov-green" title="indexed">{{ $g['indexed'] }} ✓</span>
                                    <span class="cov-grey" title="not indexed">{{ $g['not_indexed'] }} ○</span>
                                    <span title="impressions ({{ $cov['window_days'] }}d)">{{ number_format($g['impressions']) }} impr</span>
                                    <span title="clicks ({{ $cov['window_days'] }}d)">{{ number_format($g['clicks']) }} clk</span>
                                </span>
                            </summary>
                            <div class="cov-detail">
                                <div class="cov-extra">
                                    {{ $g['indexed_pct'] }}% indexed · {{ $g['submitted'] }} submitted · {{ $g['not_submitted'] }} not submitted
                                    · CTR {{ $g['ctr'] }}% · avg pos {{ $g['avg_position'] ?? '—' }}
                                    · {{ $g['orphans'] }} orphan{{ $g['orphans'] === 1 ? '' : 's' }} · {{ $g['canonical_mismatch'] }} canonical mismatch
                                </div>
                                @foreach ($g['pages'] as $p)
                                    <div class="cov-page" wire:key="cov-{{ $p['id'] }}">
                                        <span class="cov-pill {{ $p['pill'] }}">{{ $p['pill'] === 'indexed' ? 'Indexed' : ($p['pill'] === 'submitted' ? 'Submitted' : 'Not sub') }}</span>
                                        <span class="cov-ptitle">{{ $p['title'] }}</span>
                                        <span class="cov-pmetrics">{{ number_format($p['impressions']) }} impr · {{ number_format($p['clicks']) }} clk @if ($p['position'] !== null)· #{{ $p['position'] }}@endif</span>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @empty
                        <div class="cr-empty">No published pages on this site yet.</div>
                    @endforelse
                </div>
            </div>
        @endif

        <div class="cr-card">
            <h3>Queue</h3>
            <div class="cr-row">
                <button class="cr-btn danger" wire:click="clearFailed" wire:confirm="Delete all failed job records?">Clear failed jobs</button>
                <button class="cr-btn warn" wire:click="drain" wire:confirm="Publish this site's in-flight items synchronously now?"
                        @disabled(! $this->hasInFlight())>Drain publishing (this site)</button>
                <button class="cr-btn warn" wire:click="resetPublishing" wire:confirm="Reset stuck publishing states for this site?">Reset stuck publishing</button>
                <button class="cr-btn" wire:click="resetRenders" wire:confirm="Requeue all failed image renders (platform-wide)?">Reset failed renders</button>
            </div>
            @if (count($failures) > 0)
                @foreach ($failures as $f)
                    <div class="cr-fail"><strong>{{ $f['job'] }}</strong> ×{{ $f['count'] }} — {{ $f['reason'] }}</div>
                @endforeach
            @endif
        </div>

        <div class="cr-card">
            <h3>Re-sync to WordPress</h3>
            <div class="cr-empty">Push corrected data back to the live site. Site-wide chrome and categories never ride an individual page publish — re-sync them here after a fix.</div>
            @php $chrome = $this->chromeStatus; @endphp
            @if ($chrome['selected'])
                @if ($chrome['never'])
                    <div class="cr-fail" style="border-left-color:rgba(217,119,6,.6);color:#b45309;">⚠ Header &amp; footer has <b>never been synced</b> to this site — the nav won't render until you push it. Click <b>Sync header &amp; footer</b>.</div>
                @elseif ($chrome['stale'])
                    <div class="cr-fail" style="border-left-color:rgba(217,119,6,.6);color:#b45309;">⚠ The menu/profile <b>changed since the last sync</b> ({{ $chrome['synced_at'] }}). The live header is stale — click <b>Sync header &amp; footer</b> to push it.</div>
                @else
                    <div class="cr-empty" style="color:#15803d;">✓ Header &amp; footer is up to date (synced {{ $chrome['synced_at'] }}).</div>
                @endif
            @endif
            <div class="cr-row">
                <button class="cr-btn" wire:click="syncChrome" wire:confirm="Push this site's header, footer, and nav menu to WordPress?"
                        @disabled($this->siteId === null)>Sync header &amp; footer</button>
                <button class="cr-btn" wire:click="syncPages" wire:confirm="Re-push every published page &amp; post for this site to WordPress?"
                        @disabled($this->siteId === null)>Sync pages</button>
                <button class="cr-btn" wire:click="syncSilos" wire:confirm="Re-push this site's silo categories to WordPress?"
                        @disabled($this->siteId === null)>Sync silo categories</button>
            </div>
        </div>

        <div class="cr-card">
            <h3>Index coverage</h3>
            <div class="cr-empty">Ask Google (via URL Inspection) whether each published page is actually indexed, and cache the verdict + crawl date so the Live/blog cards show the real “Indexed” state instead of the impressions proxy. Runs automatically every week — this button refreshes this tenant now. Quota-guarded and cached, so it’s safe to re-run.</div>
            <div class="cr-row">
                <button class="cr-btn" wire:click="refreshIndexCoverage" wire:confirm="Run a Google index-coverage audit for this site now?"
                        @disabled($this->siteId === null)>Refresh index coverage</button>
            </div>
        </div>

        <div class="cr-card">
            <h3>Rankings</h3>
            <div class="cr-empty">Pull fresh organic + local-pack positions from DataForSEO for this tenant’s tracked keywords now — the on-demand twin of the nightly pipeline. Rankings update on the Live cards within ~5–15 minutes. <b>Uses DataForSEO credits.</b></div>
            @php $rank = $this->rankingEstimate; @endphp
            <div class="cr-empty" style="{{ $rank['empty'] ? 'color:#b45309;' : '' }}">{{ $rank['label'] }}</div>
            <div class="cr-row">
                <button class="cr-btn warn" wire:click="refreshRankings"
                        wire:confirm="Pull fresh rankings from DataForSEO now? This uses credits — {{ $rank['label'] }}."
                        @disabled($this->siteId === null || $rank['empty'])>Refresh rankings now</button>
            </div>
        </div>

        <div class="cr-card">
            <h3>Weather alert bar</h3>
            <div class="cr-empty">The severe-weather (rain) strip above the header. Off by default — turn it on only for tenants where it's relevant (e.g. sump pumps). It needs coordinates + a Contact page, and goes live on the next “Sync header &amp; footer”.</div>
            <label class="cr-lock" style="cursor:pointer;">
                <span>Show the weather alert bar on this tenant</span>
                <input type="checkbox" wire:click="toggleWeatherAlert" @checked($this->weatherAlertEnabled) @disabled($this->siteId === null)>
            </label>
        </div>

        <div class="cr-card">
            <h3>Locked pages</h3>
            @forelse ($locked as $l)
                <div class="cr-lock" wire:key="lock-{{ $l['id'] }}">
                    <span>{{ $l['title'] ?: 'Untitled' }} <em style="color:#94a3b8;">({{ $l['locked'] ? 'locked' : 'edited in WordPress' }})</em></span>
                    <button class="cr-btn" wire:click="unlock('{{ $l['id'] }}')">Unlock</button>
                </div>
            @empty
                <div class="cr-empty">No pages are locked or WordPress-edited on this site.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
