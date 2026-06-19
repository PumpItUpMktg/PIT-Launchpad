<x-filament-panels::page>
    {{-- Self-contained styling (namespaced .lp-*) — a custom Filament page's classes aren't in
         the compiled app.css and the deploy doesn't build a theme, so the design ships inline.
         Presentation only; every wire:model / wire:click binding is unchanged. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=Inter:wght@400;500;600&family=Spline+Sans+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        .lp-wrap { --ink:#13343b; --muted:#5b7178; --line:#e3eaec; --surface:#fff; --surface-2:#f5f8f8; --accent:#0E6B6B;
            --core:#0E6B6B; --adjacent:#2563eb; --connecting:#d97706; --pending:#b45309;
            font-family:'Inter',ui-sans-serif,system-ui,sans-serif; color:var(--ink); display:flex; flex-direction:column; gap:16px; }
        .dark .lp-wrap { --ink:#e6eef0; --muted:#9fb3b8; --line:#23373c; --surface:#0f2226; --surface-2:#13292e; }
        .lp-wrap * { box-sizing:border-box; }
        .lp-card { background:var(--surface); border:1px solid var(--line); border-radius:14px; padding:20px; box-shadow:0 1px 2px rgba(13,52,52,.04); }
        .lp-muted { color:var(--muted); font-size:13px; }
        .lp-label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; }
        .lp-select { padding:7px 10px; border:1px solid var(--line); border-radius:9px; background:var(--surface); color:var(--ink); font-size:13px; font-family:inherit; }
        .lp-input { padding:7px 10px; border:1px solid var(--line); border-radius:9px; background:var(--surface); color:var(--ink); font-size:13px; font-family:inherit; }
        .lp-select.pending { border-color:var(--pending); box-shadow:0 0 0 1px var(--pending) inset; }
        .lp-warn { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; border-radius:12px; padding:12px 16px; font-size:13px; }
        .lp-ok { background:#e7f6ee; color:#1b7a47; border:1px solid #b6e2c8; border-radius:14px; padding:20px; font-size:14px; }
        .lp-btn { display:inline-flex; align-items:center; gap:6px; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:600; background:var(--accent); color:#fff; border:0; cursor:pointer; }
        .lp-btn:hover { filter:brightness(1.06); }
        .lp-btn.ghost { background:var(--surface-2); color:var(--ink); border:1px solid var(--line); }
        .lp-btn.go { background:#1b7a47; }

        /* Summary stat strip */
        .lp-summary { position:sticky; top:8px; z-index:20; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:14px;
            background:var(--surface); border:1px solid var(--line); border-radius:14px; padding:14px 18px; box-shadow:0 2px 10px rgba(13,52,52,.06); }
        .lp-stats { display:flex; gap:26px; }
        .lp-stat .n { font-family:'Archivo',sans-serif; font-weight:700; font-size:24px; line-height:1; }
        .lp-stat .l { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin-top:4px; }
        .lp-stat.build .n { color:#1b7a47; }
        .lp-stat.pending .n { color:var(--pending); }

        /* Silo card */
        .lp-silo { background:var(--surface); border:1px solid var(--line); border-left:5px solid var(--spine, var(--accent)); border-radius:14px; padding:18px 20px; display:flex; flex-direction:column; gap:14px; }
        .lp-silo-head { display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px; border-bottom:1px solid var(--line); padding-bottom:12px; }
        .lp-silo-title { font-family:'Archivo',sans-serif; font-weight:700; font-size:17px; }
        .lp-silo-pillar { font-size:12px; color:var(--muted); font-family:'Spline Sans Mono',monospace; margin-top:2px; }
        .lp-silo-meta { font-size:12px; color:var(--muted); margin-top:4px; }
        .lp-silo-actions { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }

        /* Section blocks */
        .lp-core { background:#f0f8f4; border:1px solid #cde9da; border-radius:11px; padding:12px; display:flex; flex-direction:column; gap:8px; }
        .dark .lp-core { background:rgba(27,122,71,.08); border-color:rgba(27,122,71,.25); }
        .lp-sec { display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .lp-sec-h { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); }
        .lp-sec-h.core { color:#1b7a47; }
        .lp-leanins { display:flex; flex-direction:column; gap:8px; }

        /* Spoke row */
        .lp-spoke { display:grid; grid-template-columns: minmax(0,1fr) 200px auto auto; align-items:center; gap:12px;
            border:1px solid var(--line); border-radius:10px; padding:8px 12px; background:var(--surface); }
        .lp-spoke.pending { background:#fffaf2; border-color:#f0d9b8; }
        .dark .lp-spoke.pending { background:rgba(180,83,9,.08); border-color:rgba(180,83,9,.3); }
        .lp-spoke-name { font-size:14px; font-weight:500; }
        .lp-spoke-note { font-size:12px; color:var(--muted); margin-top:2px; }
        .lp-density { display:flex; align-items:center; gap:8px; }
        .lp-density-track { flex:1; height:7px; border-radius:999px; background:var(--surface-2); overflow:hidden; min-width:70px; }
        .lp-density-track > span { display:block; height:100%; border-radius:999px; }
        .lp-vol { font-family:'Spline Sans Mono',monospace; font-size:12px; color:var(--muted); min-width:48px; text-align:right; }
        .lp-badge { justify-self:start; border-radius:999px; padding:2px 9px; font-size:11px; font-weight:600; color:#fff; background:var(--bg, #94a3b8); white-space:nowrap; }
        .lp-controls { display:flex; flex-wrap:wrap; gap:6px; justify-content:flex-end; }

        /* Fringe */
        .lp-fringe-grid { display:flex; flex-direction:column; gap:8px; }
        .lp-fringe-row { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; font-size:14px; }
        @media (max-width: 720px) { .lp-spoke { grid-template-columns: 1fr; } .lp-controls { justify-content:flex-start; } }
    </style>

    @php
        $tagMeta = [
            'core' => ['label' => 'Core', 'color' => '#0E6B6B'],
            'adjacent' => ['label' => 'Adjacent', 'color' => '#2563eb'],
            'connecting' => ['label' => 'Connecting', 'color' => '#d97706'],
            'fringe' => ['label' => 'Fringe', 'color' => '#94a3b8'],
        ];
        $spinePalette = ['#0A4F4F', '#0E6B6B', '#2563eb', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#16a34a', '#b45309', '#4E9A98'];
    @endphp

    <div class="lp-wrap">
        @if (! $started)
            <div class="lp-card">
                <p class="lp-muted" style="margin:0 0 14px">
                    Pick a site with a candidate tree, then walk it into a confirmed blueprint: batch-confirm the stated
                    core, route the volume-sorted lean-ins, fold thin silos, and quick-route the fringe. Nothing is built
                    until you finalize — un-reviewed candidates are dropped.
                </p>
                <div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px">
                    <label style="flex:1; min-width:220px">
                        <span class="lp-label">Site</span>
                        <select wire:model.live="siteId" class="lp-select" style="width:100%">
                            <option value="">Select a site…</option>
                            @foreach ($this->siteOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($this->hasCandidates)
                        <button type="button" wire:click="start" class="lp-btn">✂ Open prune</button>
                    @endif
                </div>

                @if ($siteId && ! $this->hasCandidates)
                    <div class="lp-warn" style="margin-top:14px">
                        No candidate tree yet — run <code>launchpad:silo-expand --persist</code> for this site first.
                    </div>
                @endif
            </div>
        @elseif ($finalized)
            <div class="lp-ok">
                Blueprint confirmed — the directed-coverage layer is locked. This is the page inventory generation builds from.
            </div>
        @else
            @php $p = $this->preview; @endphp

            {{-- Summary stat strip + actions --}}
            <div class="lp-summary">
                <div class="lp-stats">
                    <div class="lp-stat build"><div class="n">{{ $p['built'] }}</div><div class="l">pages to build</div></div>
                    <div class="lp-stat"><div class="n">{{ $p['skipped'] }}</div><div class="l">skipped</div></div>
                    <div class="lp-stat pending"><div class="n">{{ $p['pending'] }}</div><div class="l">pending → dropped</div></div>
                </div>
                <div style="display:flex; gap:8px">
                    <button type="button" wire:click="saveDraft" class="lp-btn ghost">⌖ Save draft</button>
                    <button type="button" wire:click="finalize" class="lp-btn go"
                        wire:confirm="Finalize? {{ $p['pending'] }} pending candidate(s) will be dropped (not built).">✓ Finalize</button>
                </div>
            </div>

            @foreach ($this->bySilo as $silo => $rows)
                @php
                    $s = $this->summaries[$silo];
                    $core = array_filter($rows, fn ($r) => $r->tag->value === 'core');
                    $leanIns = array_filter($rows, fn ($r) => in_array($r->tag->value, ['adjacent', 'connecting'], true));
                    $foldTargets = array_values(array_diff(array_keys($this->bySilo), [$silo]));
                    $spine = $spinePalette[$loop->index % count($spinePalette)];
                    $pillar = collect($rows)->firstWhere('isPillar', true);
                    $maxVol = max(1, (int) collect($rows)->max(fn ($r) => (int) $r->volume));
                @endphp

                <div class="lp-silo" style="--spine: {{ $spine }}">
                    <div class="lp-silo-head">
                        <div>
                            <div class="lp-silo-title">{{ $silo }}</div>
                            @if ($pillar)
                                <div class="lp-silo-pillar">{{ $pillar->name }}@if ($pillar->volume !== null) · {{ number_format((int) $pillar->volume) }} searches @endif</div>
                            @endif
                            <div class="lp-silo-meta">{{ $s['total'] }} spokes · {{ $s['core'] }} core · {{ $s['lean_ins'] }} lean-in @ {{ number_format((int) $s['lean_in_volume']) }} searches</div>
                        </div>
                        <div class="lp-silo-actions">
                            <input type="text" wire:model="siloDecisions.{{ $silo }}.rename" placeholder="rename…" class="lp-input" style="width:130px" />
                            <select wire:model="siloDecisions.{{ $silo }}.fold_into" class="lp-select" style="width:150px">
                                <option value="">fold into…</option>
                                @foreach ($foldTargets as $target)
                                    <option value="{{ $target }}">{{ $target }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @if (count($core))
                        <div class="lp-core">
                            <div class="lp-sec">
                                <span class="lp-sec-h core">Stated core — verify</span>
                                <button type="button" wire:click="confirmCore('{{ $silo }}')" class="lp-btn ghost" style="padding:5px 11px">✓ Confirm all core</button>
                            </div>
                            @foreach ($core as $row)
                                @include('filament.pages.partials.prune-row', ['row' => $row, 'maxVol' => $maxVol, 'tagMeta' => $tagMeta])
                            @endforeach
                        </div>
                    @endif

                    @if (count($leanIns))
                        <div class="lp-leanins">
                            <span class="lp-sec-h">Lean-ins — the focus (highest volume first)</span>
                            @foreach ($leanIns as $row)
                                @include('filament.pages.partials.prune-row', ['row' => $row, 'maxVol' => $maxVol, 'tagMeta' => $tagMeta])
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Fringe handoff --}}
            @if (count($this->fringe))
                <div class="lp-card">
                    <span class="lp-sec-h">Fringe handoff — refer out / sibling brand (no pages built)</span>
                    <div class="lp-fringe-grid" style="margin-top:10px">
                        @foreach ($this->fringe as $row)
                            <div class="lp-fringe-row">
                                <span>{{ $row->name }}@if ($row->connectionNote)<span class="lp-muted"> — {{ $row->connectionNote }}</span>@endif</span>
                                <select wire:model="spokeDecisions.{{ $row->id }}.outcome" class="lp-select" style="width:230px">
                                    <option value="">— handoff —</option>
                                    <option value="skipped">Refer out / sibling brand</option>
                                </select>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
