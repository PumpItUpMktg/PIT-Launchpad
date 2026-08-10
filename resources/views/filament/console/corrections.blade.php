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
