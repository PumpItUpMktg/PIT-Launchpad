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

        /* Drag-to-re-home + aligned grid rows */
        .lp-spoke-list { display:flex; flex-direction:column; gap:8px; min-height:8px; }
        .lp-drag { cursor:grab; color:var(--muted); font-size:13px; user-select:none; }
        .lp-row { display:grid; grid-template-columns: minmax(0,1fr) 84px 110px 164px; align-items:center; column-gap:12px;
            padding:7px 12px; border:1px solid var(--line); border-radius:9px; background:var(--surface); }
        .lp-row.pending { background:#fffaf2; border-color:#f0d9b8; }
        .dark .lp-row.pending { background:rgba(180,83,9,.08); border-color:rgba(180,83,9,.3); }
        .lp-row.lp-ghost { opacity:.4; }
        .lp-row-head { background:none; border:0; padding:2px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); }
        .lp-cell-name { display:flex; flex-wrap:wrap; align-items:center; gap:6px; min-width:0; }
        .lp-row-name { font-size:14px; font-weight:500; }
        .lp-row-note { flex-basis:100%; font-size:12px; color:var(--muted); }
        .lp-cell-vol { font-family:'Spline Sans Mono',monospace; font-size:12px; color:var(--muted); text-align:right; }
        .lp-cell-page { display:flex; flex-wrap:wrap; gap:6px; }
        .lp-cell-page .lp-select { width:100%; }
        .lp-silo.lp-drop { box-shadow:0 0 0 2px var(--accent); }
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
                    <div class="lp-stat build"><div class="n">{{ $p['pages'] }}</div><div class="l">pages to build</div></div>
                    <div class="lp-stat"><div class="n">{{ $p['folded'] }}</div><div class="l">folded sections</div></div>
                    <div class="lp-stat"><div class="n">{{ $p['dropped'] }}</div><div class="l">handoff / dropped</div></div>
                </div>
                <div style="display:flex; gap:8px">
                    <button type="button" wire:click="applyUpdate" class="lp-btn ghost">↻ Update</button>
                    <button type="button" wire:click="finalize" class="lp-btn go"
                        wire:confirm="Finalize? {{ $p['pending'] }} pending candidate(s) will be dropped (not built).">✓ Finalize</button>
                </div>
            </div>

            @foreach ($this->bySilo as $silo => $rows)
                @php
                    $s = $this->summaries[$silo];
                    $pillar = collect($rows)->firstWhere('isPillar', true);
                    $siloFoldTargets = array_values(array_diff(array_keys($this->bySilo), [$silo]));
                    $spine = $spinePalette[$loop->index % count($spinePalette)];
                    $isDead = in_array($silo, $this->deadSilos, true);

                    // Disposition comes from the live decision-set (granularity + fold_into per id),
                    // not just the persisted spoke — so a toggle/drag re-nests immediately.
                    $dispositionOf = fn ($r) => $this->spokeDecisions[$r->id]['granularity'] ?? $r->granularity->value;
                    $targetOf = fn ($r) => $this->spokeDecisions[$r->id]['fold_into'] ?? '';

                    // Parents = own-page cores (volume desc). The pillar is the silo-hub parent.
                    $parents = array_values(array_filter($rows, fn ($r) => ! $r->isPillar && $dispositionOf($r) === 'own_page'));
                    usort($parents, fn ($a, $b) => ($b->volume ?? -1) <=> ($a->volume ?? -1));
                    $pillarId = $pillar?->id ?? '';
                    $parentIds = array_merge([$pillarId], array_map(fn ($p) => $p->id, $parents));

                    // Folded children grouped under their fold target; a target that isn't a valid
                    // parent (it folded too) re-points to the pillar — can't nest under a folded page.
                    $childrenByParent = [];
                    foreach ($rows as $r) {
                        if ($r->isPillar || $dispositionOf($r) === 'own_page') { continue; }
                        $target = $targetOf($r);
                        if ($target === '' || ! in_array($target, $parentIds, true)) { $target = $pillarId; }
                        $childrenByParent[$target][] = $r;
                    }
                    foreach ($childrenByParent as &$kids) {
                        usort($kids, fn ($a, $b) => ($b->volume ?? -1) <=> ($a->volume ?? -1));
                    }
                    unset($kids);

                    // Re-target options: the pillar + the own-page cores (a folded page can't be a parent).
                    $foldOptions = [];
                    if ($pillar) { $foldOptions[$pillar->id] = $pillar->name.' (pillar)'; }
                    foreach ($parents as $p) { $foldOptions[$p->id] = $p->name; }
                @endphp

                <div class="lp-silo" style="--spine: {{ $spine }}" wire:key="lp-silo-{{ \Illuminate\Support\Str::slug($silo) }}">
                    <div class="lp-silo-head">
                        <div>
                            @php $deadTag = $isDead ? ' — dead, fold suggested' : ''; @endphp
                            <div class="lp-silo-title">{{ $silo }}<span class="lp-muted" style="font-weight:500">{{ $deadTag }}</span></div>
                            @if ($pillar)
                                @php $pillarVol = $pillar->volume !== null ? ' · '.number_format((int) $pillar->volume).' searches' : ''; @endphp
                                <div class="lp-silo-pillar">⬡ {{ $pillar->name }} · category hub · always built{{ $pillarVol }}</div>
                            @endif
                            <div class="lp-silo-meta">{{ $s['total'] }} spokes · {{ $s['core'] }} core · {{ $s['lean_ins'] }} supporting @ {{ number_format((int) $s['lean_in_volume']) }} searches</div>
                        </div>
                        <div class="lp-silo-actions">
                            <input type="text" wire:model="siloDecisions.{{ $silo }}.rename" placeholder="rename…" class="lp-input" style="width:130px" />
                            <select wire:model.live="siloDecisions.{{ $silo }}.fold_into" @class(['lp-select', 'pending' => $isDead]) style="width:150px">
                                <option value="">fold silo into…</option>
                                @foreach ($siloFoldTargets as $target)
                                    <option value="{{ $target }}">{{ $target }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="confirmCore('{{ $silo }}')" class="lp-btn ghost" style="padding:5px 11px">✓ Confirm all core</button>
                        </div>
                    </div>

                    {{-- Column header --}}
                    <div class="lp-row lp-row-head">
                        <div class="lp-cell-name">Keyword</div>
                        <div class="lp-cell-vol">Searches</div>
                        <div class="lp-cell-tag">Tag</div>
                        <div class="lp-cell-page">Page</div>
                    </div>

                    {{-- Parents (own-page cores + the pillar hub) with their folded children nested beneath.
                         One sortable list per silo so a row can still be dragged to another silo. --}}
                    <div class="lp-spoke-list" data-prune-list data-silo-name="{{ $silo }}">
                        {{-- Pillar group: spokes folding into the hub --}}
                        @foreach ($childrenByParent[$pillarId] ?? [] as $row)
                            @include('filament.pages.partials.prune-row', ['row' => $row, 'depth' => 1, 'tagMeta' => $tagMeta, 'foldOptions' => $foldOptions])
                        @endforeach

                        @foreach ($parents as $parent)
                            @include('filament.pages.partials.prune-row', ['row' => $parent, 'depth' => 0, 'tagMeta' => $tagMeta, 'foldOptions' => $foldOptions])
                            @foreach ($childrenByParent[$parent->id] ?? [] as $row)
                                @include('filament.pages.partials.prune-row', ['row' => $row, 'depth' => 1, 'tagMeta' => $tagMeta, 'foldOptions' => $foldOptions])
                            @endforeach
                        @endforeach
                    </div>
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

    {{-- Drag-to-re-home (SortableJS, CDN-loaded like the Locations map — custom-page assets
         aren't reliably bundled). Dragging a spoke's ⠿ handle into ANOTHER silo's list re-homes
         it (fold into that silo) via the canonical moveSpoke. Promote/demote + precise core
         retarget stay on the auto-applying controls. Fully guarded: a load failure degrades to
         "no drag", never a thrown init, and the controls remain the reliable path. --}}
    <script>
        window.lpPruneSortable = () => ({
            init() {
                this.ensure(() => this.bind());
                // Re-bind after every Livewire morph (the lists are re-rendered on re-derive).
                if (window.Livewire && ! this._hooked) {
                    this._hooked = true;
                    window.Livewire.hook('morph.updated', () => queueMicrotask(() => this.bind()));
                }
            },
            ensure(cb) {
                if (window.Sortable) return cb();
                const id = 'lp-sortablejs';
                const existing = document.getElementById(id);
                if (existing) { existing.addEventListener('load', cb); return; }
                const s = document.createElement('script');
                s.id = id;
                s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
                s.onload = cb;
                s.onerror = () => console.error('SortableJS failed to load — drag disabled, controls still work');
                document.head.appendChild(s);
            },
            bind() {
                try {
                    document.querySelectorAll('[data-prune-list]').forEach((el) => {
                        if (el._lpSortable) return; // already bound this DOM node
                        el._lpSortable = window.Sortable.create(el, {
                            group: 'lp-prune-spokes',
                            handle: '.lp-drag',
                            animation: 150,
                            ghostClass: 'lp-ghost',
                            onEnd: (evt) => {
                                const from = evt.from?.getAttribute('data-silo-name');
                                const to = evt.to?.getAttribute('data-silo-name');
                                const id = evt.item?.getAttribute('data-spoke-id');
                                if (! id || ! to || from === to) return; // same-silo reorder = cosmetic, ignore
                                // Cross-silo: re-home + fold into the destination silo (canonical mutation).
                                this.$wire.moveSpoke(id, 'silo', to);
                            },
                        });
                    });
                } catch (e) { console.error('prune sortable bind', e); }
            },
        });
    </script>
    <div x-data="lpPruneSortable()" x-init="init()"></div>
</x-filament-panels::page>
