<x-filament-panels::page>
<div class="gac" wire:poll.3s>
    <style>
        .gac { --gac-line:#e2e7ee; --gac-muted:#5a6675; --gac-faint:#8a95a3; --gac-surface2:#f6f8fb; --gac-accent:#2563eb; }
        .dark .gac { --gac-line:#232c37; --gac-muted:#9aa7b5; --gac-faint:#6b7887; --gac-surface2:#0f151c; --gac-accent:#60a5fa; }
        .gac .gac-controls { display:flex; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
        .gac .gac-select { font-size:13px; border:1px solid var(--gac-line); border-radius:8px; padding:7px 12px; background:transparent; color:inherit; }
        .gac .gac-status { display:flex; align-items:center; gap:10px; font-size:13px; }
        .gac .gac-dot { width:9px; height:9px; border-radius:50%; }
        .gac .gac-idle { background:var(--gac-faint); }
        .gac .gac-live { background:#16a34a; box-shadow:0 0 0 0 rgba(22,163,74,.5); animation:gac-pulse 1.4s infinite; }
        @keyframes gac-pulse { 70% { box-shadow:0 0 0 7px rgba(22,163,74,0); } 100% { box-shadow:0 0 0 0 rgba(22,163,74,0); } }
        .gac .gac-now { margin:6px 0 18px; padding:12px 14px; border:1px solid var(--gac-line); border-radius:12px; background:var(--gac-surface2); font-size:13.5px; }
        .gac .gac-now b { color:var(--gac-accent); }
        .gac .gac-now .gac-q { color:var(--gac-muted); }
        .gac .gac-progress { height:7px; border-radius:4px; background:var(--gac-line); overflow:hidden; margin-top:10px; max-width:360px; }
        .gac .gac-progress i { display:block; height:100%; background:var(--gac-accent); transition:width .4s ease; }
        .gac .gac-lanes { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin:6px 0 22px; }
        .gac .gac-lane { border:1px solid var(--gac-line); border-radius:12px; padding:14px; }
        .gac .gac-lane.gac-lane-live { border-color:#16a34a; }
        .gac .gac-lane h4 { font-size:14px; font-weight:800; margin:0 0 4px; display:flex; align-items:center; gap:8px; }
        .gac .gac-lane .gac-tally { display:flex; gap:16px; flex-wrap:wrap; font-size:12px; color:var(--gac-muted); margin-top:6px; }
        .gac .gac-lane .gac-tally b { color:inherit; font-weight:800; font-variant-numeric:tabular-nums; }
        .gac h3.gac-h { font-size:14px; font-weight:700; margin:8px 0 10px; }
        .gac .gac-feed { max-height:360px; overflow-y:auto; border:1px solid var(--gac-line); border-radius:12px; }
        .gac .gac-row { display:flex; align-items:baseline; gap:10px; padding:8px 12px; border-bottom:1px solid var(--gac-line); font-size:13px; }
        .gac .gac-row:last-child { border-bottom:0; }
        .gac .gac-rowdot { width:8px; height:8px; border-radius:50%; flex:none; align-self:center; }
        .gac .gac-where { font-weight:600; }
        .gac .gac-eng { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--gac-faint); }
        .gac .gac-cited { color:#15803d; font-weight:700; }
        .gac .gac-absent { color:#c0392b; font-weight:700; }
        .gac .gac-comp { color:#c0392b; font-size:11.5px; }
        .gac .gac-act { margin-left:auto; font-size:11.5px; color:var(--gac-muted); white-space:nowrap; }
        .gac .gac-empty { padding:36px 16px; text-align:center; color:var(--gac-muted); border:1px dashed var(--gac-line); border-radius:12px; }
    </style>

    <div class="gac-controls">
        @if (count($this->sites) > 1)
            <select class="gac-select" wire:model.live="siteId" aria-label="Tenant">
                @foreach ($this->sites as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        @if ($this->console !== null)
            <span class="gac-status">
                <span class="gac-dot {{ $this->console['running'] ? 'gac-live' : 'gac-idle' }}"></span>
                @if ($this->console['running'])
                    <span>Checking now @if ($this->console['started_at']) · started {{ $this->console['started_at']->diffForHumans() }} @endif</span>
                @else
                    <span style="color:var(--gac-muted)">Idle — no check running. Run a GEO check to watch it work.</span>
                @endif
            </span>
        @endif
    </div>

    @if ($this->console === null)
        <div class="gac-empty">Pick a tenant to watch its AI-visibility checks.</div>
    @else
        @php($c = $this->console)

        {{-- Now contacting --}}
        @if (! empty($c['contacting']))
            <div class="gac-now">
                → Contacting <b>{{ ucfirst($c['contacting']['engine']) }}</b>
                @if (! empty($c['contacting']['town'])) for <b>{{ $c['contacting']['town'] }}</b>@endif:
                <span class="gac-q">“{{ $c['contacting']['prompt'] }}”</span>
                @if ($c['total'] > 0)
                    <div class="gac-progress"><i style="width: {{ (int) round($c['measured'] / max(1, $c['total']) * 100) }}%"></i></div>
                    <div style="font-size:11.5px;color:var(--gac-faint);margin-top:5px;">{{ $c['measured'] }}/{{ $c['total'] }} measured this run</div>
                @endif
            </div>
        @endif

        {{-- Per-engine lanes --}}
        @if (count($c['engines']) > 0)
            <div class="gac-lanes">
                @foreach ($c['engines'] as $e)
                    <div class="gac-lane {{ $e['contacting'] ? 'gac-lane-live' : '' }}">
                        <h4>
                            <span class="gac-dot {{ $e['contacting'] ? 'gac-live' : 'gac-idle' }}"></span>
                            {{ $e['name'] }}
                        </h4>
                        <div style="font-size:11.5px;color:var(--gac-faint)">{{ $e['contacting'] ? 'contacting…' : 'idle' }}</div>
                        <div class="gac-tally">
                            <span><b>{{ $e['measured'] }}</b> measured</span>
                            <span><b>{{ $e['cited'] }}</b> cited</span>
                            <span><b>{{ $e['skipped'] }}</b> fresh</span>
                            <span><b>{{ $e['deferred'] }}</b> deferred</span>
                            @if ($e['errors'] > 0)<span><b>{{ $e['errors'] }}</b> errors</span>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Streaming feed --}}
        <h3 class="gac-h">Latest run — newest first</h3>
        @if (count($c['feed']) > 0)
            <div class="gac-feed">
                @foreach ($c['feed'] as $row)
                    <div class="gac-row">
                        <span class="gac-rowdot" style="background: {{ $row['color'] }}"></span>
                        <span class="gac-where">{{ $row['town'] ?? 'Service-wide' }}</span>
                        <span class="gac-eng">{{ $row['engine'] }}</span>
                        @if ($row['is_measured'])
                            @if ($row['cited'])
                                <span class="gac-cited">cited</span>
                            @else
                                <span class="gac-absent">absent</span>@if (! empty($row['competitors']))<span class="gac-comp">— {{ implode(', ', array_slice($row['competitors'], 0, 3)) }}</span>@endif
                            @endif
                        @endif
                        <span class="gac-act">{{ $row['action'] }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="gac-empty">No check has run for this tenant yet. Seed prompts, then run a GEO check.</div>
        @endif

        <p style="margin-top:16px;font-size:12px;color:var(--gac-faint)">Live — updates every few seconds while a check runs. Each reading is one sampled AI answer per engine.</p>
    @endif
</div>
</x-filament-panels::page>
